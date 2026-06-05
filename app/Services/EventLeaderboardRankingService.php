<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\EventProgressSubmission;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Leaderboard ordering: finishers rank by who completed the goal first; others by logged km.
 */
class EventLeaderboardRankingService
{
    public function completionColumnReady(): bool
    {
        return Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at');
    }

    public function applySqlOrdering(Builder $query, bool $progressReady): void
    {
        if (! $progressReady) {
            $query->orderByDesc('created_at');

            return;
        }

        if ($this->completionColumnReady()) {
            $query
                ->orderByRaw('CASE WHEN progress_goal_completed_at IS NOT NULL THEN 0 ELSE 1 END ASC')
                ->orderByRaw('progress_goal_completed_at ASC')
                ->orderByRaw('COALESCE(progress_logged_km, 0) DESC')
                ->orderByRaw('CASE WHEN progress_pace_min_per_km IS NULL OR progress_pace_min_per_km <= 0 THEN 999999 ELSE progress_pace_min_per_km END ASC')
                ->orderBy('updated_at');

            return;
        }

        $query
            ->orderByRaw('COALESCE(progress_logged_km, 0) DESC')
            ->orderByRaw('CASE WHEN progress_pace_min_per_km IS NULL OR progress_pace_min_per_km <= 0 THEN 999999 ELSE progress_pace_min_per_km END ASC')
            ->orderBy('updated_at');
    }

    public function rankForRegistration(
        AdminEvent $event,
        Builder $baseQuery,
        ClientAdminEventRegistration $target,
        bool $progressReady,
    ): int {
        if (! $progressReady) {
            return 1 + (clone $baseQuery)
                ->where('created_at', '>', $target->created_at)
                ->count();
        }

        /** @var Collection<int, ClientAdminEventRegistration> $regs */
        $regs = (clone $baseQuery)->get([
            'id',
            'client_id',
            'admin_event_id',
            'progress_logged_km',
            'progress_goal_km',
            'progress_pace_min_per_km',
            'progress_goal_completed_at',
            'updated_at',
            'created_at',
        ]);

        if ($regs->isEmpty()) {
            return 1;
        }

        $finisherByClient = $this->buildFinisherLookup($event, $regs);
        $sorted = $regs
            ->sort(fn (ClientAdminEventRegistration $a, ClientAdminEventRegistration $b) => $this->compareRegistrations(
                $event,
                $a,
                $b,
                $finisherByClient,
            ))
            ->values();

        $position = $sorted->search(
            fn (ClientAdminEventRegistration $reg) => (string) $reg->client_id === (string) $target->client_id
        );

        return $position === false ? $sorted->count() + 1 : $position + 1;
    }

    /**
     * @param  array<string, bool>|null  $finisherByClient
     */
    public function compareRegistrations(
        AdminEvent $event,
        ClientAdminEventRegistration $a,
        ClientAdminEventRegistration $b,
        ?array $finisherByClient = null,
    ): int {
        $finA = $finisherByClient[(string) $a->client_id]
            ?? $this->registrationIsFinisher($event, $a);
        $finB = $finisherByClient[(string) $b->client_id]
            ?? $this->registrationIsFinisher($event, $b);

        if ($finA !== $finB) {
            return $finB <=> $finA;
        }

        if ($finA && $finB) {
            $timeA = $this->completionSortKey($a);
            $timeB = $this->completionSortKey($b);
            if ($timeA !== $timeB) {
                return $timeA <=> $timeB;
            }
        } else {
            $loggedA = (float) ($a->progress_logged_km ?? 0);
            $loggedB = (float) ($b->progress_logged_km ?? 0);
            if (abs($loggedA - $loggedB) > 1e-6) {
                return $loggedB <=> $loggedA;
            }
        }

        $paceA = $this->paceSortValue($a->progress_pace_min_per_km);
        $paceB = $this->paceSortValue($b->progress_pace_min_per_km);
        if ($paceA !== $paceB) {
            return $paceA <=> $paceB;
        }

        $updatedA = $a->updated_at?->getTimestamp() ?? 0;
        $updatedB = $b->updated_at?->getTimestamp() ?? 0;

        return $updatedA <=> $updatedB;
    }

    public function registrationIsFinisher(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
        ?Collection $selectionsByClient = null,
    ): bool {
        if ($this->completionColumnReady() && $reg->progress_goal_completed_at !== null) {
            return true;
        }

        $goal = $this->effectiveGoalKm($event, $reg, $selectionsByClient);
        if ($goal === null || $goal <= 0) {
            return false;
        }

        $logged = (float) ($reg->progress_logged_km ?? 0);

        return $logged + 1e-6 >= max(0.0, $goal - 0.08);
    }

    public function maybeRecordGoalCompletion(
        ClientAdminEventRegistration $reg,
        ?Carbon $completedAt = null,
    ): void {
        if (! $this->completionColumnReady()) {
            return;
        }

        if ($reg->progress_goal_completed_at !== null) {
            return;
        }

        $event = AdminEvent::query()->find($reg->admin_event_id);
        if (! $event) {
            return;
        }

        if (! $this->registrationIsFinisher($event, $reg)) {
            return;
        }

        $reg->progress_goal_completed_at = $completedAt ?? now();
        $reg->saveQuietly();
    }

    /**
     * Reconstruct first goal completion from approved submission history (backfill).
     */
    public function inferCompletionTimestamp(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
        ?Collection $selectionsByClient = null,
    ): ?Carbon {
        if ($reg->progress_goal_completed_at !== null) {
            return $reg->progress_goal_completed_at;
        }

        $goal = $this->effectiveGoalKm($event, $reg, $selectionsByClient);
        if ($goal === null || $goal <= 0) {
            return null;
        }

        if (! EventProgressSubmissionService::tableReady()) {
            return $reg->updated_at;
        }

        $cumulative = 0.0;
        $submissions = EventProgressSubmission::query()
            ->where('client_id', $reg->client_id)
            ->where('admin_event_id', $event->id)
            ->where('status', EventProgressSubmission::STATUS_APPROVED)
            ->orderBy('reviewed_at')
            ->orderBy('created_at')
            ->get(['distance_delta_km', 'reviewed_at', 'created_at']);

        foreach ($submissions as $submission) {
            $cumulative += (float) $submission->distance_delta_km;
            if ($cumulative + 1e-6 >= max(0.0, $goal - 0.08)) {
                $at = $submission->reviewed_at ?? $submission->created_at;

                return $at instanceof Carbon ? $at : Carbon::parse((string) $at);
            }
        }

        return $reg->updated_at;
    }

    public function effectiveGoalKm(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
        ?Collection $selectionsByClient = null,
    ): ?float {
        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;

        if (($goal === null || $goal <= 0.0)
            && Schema::hasColumn('admin_events', 'mileage_challenge_km')
            && $event->mileage_challenge_km !== null
            && (float) $event->mileage_challenge_km > 0.0) {
            $goal = (float) $event->mileage_challenge_km;
        }

        if (($goal === null || $goal <= 0) && strtolower((string) $event->category) === 'running') {
            $sel = null;
            if ($selectionsByClient !== null) {
                $sel = $selectionsByClient->get((string) $reg->client_id);
            } elseif (Schema::hasTable('client_admin_event_running_selections')) {
                $sel = ClientAdminEventRunningSelection::query()
                    ->where('admin_event_id', $event->id)
                    ->where('client_id', $reg->client_id)
                    ->first();
            }

            if ($sel) {
                $eventChallenge = Schema::hasColumn('admin_events', 'mileage_challenge_km')
                    ? (float) ($event->mileage_challenge_km ?? 0)
                    : 0.0;
                $dkm = EventEnrollmentProgressService::distanceKeyToKm(
                    (string) $sel->distance_key,
                    $sel->distance_label !== null ? (string) $sel->distance_label : null
                );
                if ($eventChallenge > 0) {
                    $goal = $eventChallenge;
                } elseif ($dkm !== null && $dkm > 0) {
                    $goal = $dkm;
                }
            }
        }

        return ($goal !== null && $goal > 0) ? $goal : null;
    }

    /**
     * @param  Collection<int, ClientAdminEventRegistration>  $regs
     * @return array<string, bool>
     */
    public function buildFinisherLookup(AdminEvent $event, Collection $regs): array
    {
        $selectionsByClient = $this->preloadRunningSelections($event, $regs);
        $lookup = [];

        foreach ($regs as $reg) {
            $lookup[(string) $reg->client_id] = $this->registrationIsFinisher($event, $reg, $selectionsByClient);
        }

        return $lookup;
    }

    /**
     * @param  Collection<int, ClientAdminEventRegistration>  $regs
     * @return Collection<string, ClientAdminEventRunningSelection>
     */
    public function preloadRunningSelections(AdminEvent $event, Collection $regs): Collection
    {
        if (! Schema::hasTable('client_admin_event_running_selections') || $regs->isEmpty()) {
            return collect();
        }

        return ClientAdminEventRunningSelection::query()
            ->where('admin_event_id', $event->id)
            ->whereIn('client_id', $regs->pluck('client_id'))
            ->get()
            ->keyBy(static fn ($row) => (string) $row->client_id);
    }

    private function completionSortKey(ClientAdminEventRegistration $reg): int
    {
        if ($this->completionColumnReady() && $reg->progress_goal_completed_at !== null) {
            return $reg->progress_goal_completed_at->getTimestamp();
        }

        return $reg->updated_at?->getTimestamp() ?? PHP_INT_MAX;
    }

    private function paceSortValue(mixed $pace): float
    {
        if ($pace === null || (float) $pace <= 0) {
            return 999999.0;
        }

        return (float) $pace;
    }
}
