<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Ranks confirmed registrants who reached 100% progress (same ordering as the public event leaderboard).
 */
class EventFinisherRankService
{
    /**
     * 1-based rank among event finishers, or null if the client did not finish.
     */
    public function finisherRankForClient(string $eventId, string $clientId): ?int
    {
        if (! Schema::hasTable('client_admin_event_registrations')
            || ! Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km')) {
            return null;
        }

        $event = AdminEvent::query()->find($eventId);
        if (! $event) {
            return null;
        }

        $viewerReg = $this->confirmedRegistrationQuery($eventId)
            ->where('client_id', $clientId)
            ->first();

        if (! $viewerReg || ! $this->registrationReachedOneHundredPercent($event, $viewerReg)) {
            return null;
        }

        $finisherIds = $this->confirmedRegistrationQuery($eventId)
            ->with(['event'])
            ->get()
            ->filter(fn (ClientAdminEventRegistration $reg) => $this->registrationReachedOneHundredPercent($event, $reg))
            ->sort(function (ClientAdminEventRegistration $a, ClientAdminEventRegistration $b) {
                return $this->compareForLeaderboard($a, $b);
            })
            ->values()
            ->pluck('client_id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        $position = array_search((string) $clientId, $finisherIds, true);

        return $position === false ? null : $position + 1;
    }

    private function confirmedRegistrationQuery(string $eventId): Builder
    {
        $query = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $eventId)
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        if (Schema::hasColumn('client_admin_event_registrations', 'registration_status')) {
            $query->where('registration_status', 'confirmed');
        }

        return $query;
    }

    private function compareForLeaderboard(
        ClientAdminEventRegistration $a,
        ClientAdminEventRegistration $b,
    ): int {
        $loggedA = (float) ($a->progress_logged_km ?? 0);
        $loggedB = (float) ($b->progress_logged_km ?? 0);

        if ($loggedA !== $loggedB) {
            return $loggedB <=> $loggedA;
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

    private function paceSortValue(mixed $pace): float
    {
        if ($pace === null || (float) $pace <= 0) {
            return 999999.0;
        }

        return (float) $pace;
    }

    private function registrationReachedOneHundredPercent(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
    ): bool {
        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $logged = (float) ($reg->progress_logged_km ?? 0);

        if (($goal === null || $goal <= 0) && strtolower((string) $event->category) === 'running') {
            $sel = null;
            if (Schema::hasTable('client_admin_event_running_selections')) {
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

        if ($goal === null || $goal <= 0) {
            return false;
        }

        return $logged + 1e-6 >= max(0.0, $goal - 0.08);
    }
}
