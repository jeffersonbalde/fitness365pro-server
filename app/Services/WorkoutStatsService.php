<?php

namespace App\Services;

use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\CommunityMember;
use App\Models\WorkoutLog;
use App\Services\EventEnrollmentProgressService;
use App\Services\EventProgressSubmissionService;
use Illuminate\Support\Facades\Schema;

class WorkoutStatsService
{
    /**
     * Same shape as WorkoutController::stats "data" payload (no HTTP wrapper).
     */
    public function buildPayloadForClient(string $clientId): array
    {
        $completedQuery = WorkoutLog::query()
            ->where('client_id', $clientId)
            ->where('status', 'completed');

        $totalWorkouts = (clone $completedQuery)->count();

        $thisWeek = (clone $completedQuery)
            ->whereBetween('workout_date', [
                now()->startOfWeek()->toDateString(),
                now()->endOfWeek()->toDateString(),
            ])
            ->count();

        $runQuery = (clone $completedQuery)
            ->where('entry_type', 'workout')
            ->whereNotNull('distance_km')
            ->where('distance_km', '>', 0);

        $totalRuns = (clone $runQuery)->count();
        $streak = $this->calculateStreak($clientId);
        $totalDistance = (clone $runQuery)->sum('distance_km');

        $paceAgg = (clone $runQuery)
            ->whereNotNull('pace_min_per_km')
            ->selectRaw('sum(pace_min_per_km * distance_km) as weighted_pace_sum, sum(distance_km) as distance_sum')
            ->first();

        $avgPace = null;
        if ($paceAgg && (float) $paceAgg->distance_sum > 0) {
            $avgPace = round((float) ($paceAgg->weighted_pace_sum / $paceAgg->distance_sum), 2);
        }

        $joinedRaceMembers = CommunityMember::query()
            ->where('client_id', $clientId)
            ->where('status', 'active')
            ->whereHas('community', function ($q) {
                $q->where('is_active', true)
                    ->where('primary_niche', 'running');
            })
            ->with([
                'community:id,name,slug,primary_niche,is_active',
            ])
            ->orderByDesc('joined_at')
            ->limit(8)
            ->get();

        $joinedRaces = $joinedRaceMembers
            ->map(function (CommunityMember $membership) {
                $community = $membership->community;
                if (!$community) {
                    return null;
                }

                return [
                    'id' => $community->id,
                    'name' => $community->name,
                    'slug' => $community->slug,
                    'primary_niche' => $community->primary_niche,
                    'role' => $membership->role,
                    'joined_at' => $membership->joined_at?->toDateString(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'total_workouts' => $totalWorkouts,
            'this_week' => $thisWeek,
            'current_streak' => $streak,
            'total_distance_km' => round((float) $totalDistance, 2),
            'total_runs' => $totalRuns,
            'avg_pace_min_per_km' => $avgPace,
            'badges' => [],
            'trophies' => [],
            /** CMS event badges (with artwork) unlocked when enrolled challenge reaches 100% progress. */
            'event_badges' => $this->buildEarnedEventBadges($clientId),
            'joined_races' => $joinedRaces,
            'joined_challenge_events' => $this->buildJoinedChallengeEventsFromSnapshots($this->challengeSnapshotsForClient($clientId, 12)),
        ];
    }

    /**
     * Image-capable badges from admin events whose distance goal has been fully completed.
     *
     * @return list<array{id: string, event_id: string, event_title: string, badge_key: string, title: string, image_url: string}>
     */
    private function buildEarnedEventBadges(string $clientId): array
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return [];
        }

        $snapshots = $this->challengeSnapshotsForClient($clientId, 48);
        $out = [];
        $seenFingerprints = [];

        foreach ($snapshots as $snap) {
            $pct = $snap['percent'];
            if ($pct === null || abs((float) $pct - 100.0) > 1e-3) {
                continue;
            }
            $event = $snap['event'];
            foreach ($this->badgeImageCatalogFromEventBadgesRaw($event->badges ?? null) as $badge) {
                $fp = (string) $event->id.'|'.$badge['badge_key'].'|'.$badge['image_url'];
                if (isset($seenFingerprints[$fp])) {
                    continue;
                }
                $seenFingerprints[$fp] = true;

                $safeEvent = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $event->id);
                $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $badge['badge_key']);

                $out[] = [
                    'id' => 'eb_'.$safeEvent.'_'.$safeKey,
                    'event_id' => (string) $event->id,
                    'event_title' => (string) ($event->title ?? 'Event'),
                    'badge_key' => $badge['badge_key'],
                    'title' => $badge['title'],
                    'image_url' => $badge['image_url'],
                    'earned_at' => $snap['reg_updated_at'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Resolve a single earned badge for public share cards.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublicBadgeShare(string $clientId, string $eventId, string $badgeKey): ?array
    {
        $normalizedKey = $this->normalizeBadgeKeyForLookup($badgeKey);

        foreach ($this->buildEarnedEventBadges($clientId) as $badge) {
            if ((string) ($badge['event_id'] ?? '') !== (string) $eventId) {
                continue;
            }

            $candidateKey = $this->normalizeBadgeKeyForLookup((string) ($badge['badge_key'] ?? ''));
            if ($candidateKey !== $normalizedKey) {
                continue;
            }

            return $badge;
        }

        return null;
    }

    private function normalizeBadgeKeyForLookup(string $badgeKey): string
    {
        $decoded = rawurldecode(trim($badgeKey));
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $decoded);

        return strtolower((string) $safe);
    }

    /**
     * @param  mixed  $raw  admin_events.badges JSON
     * @return list<array{badge_key: string, title: string, image_url: string}>
     */
    private function badgeImageCatalogFromEventBadgesRaw(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $i => $b) {
            if (! is_array($b)) {
                continue;
            }
            $title = trim((string) ($b['title'] ?? ''));
            $imageUrl = trim((string) ($b['image_url'] ?? ''));
            if ($imageUrl === '') {
                continue;
            }
            $slug = trim((string) ($b['slug'] ?? ''));
            $key = $slug !== '' ? strtolower($slug) : 'badge_'.(is_int($i) ? $i + 1 : md5($imageUrl.'|'.$title));

            $rows[] = [
                'badge_key' => $key,
                'title' => $title !== '' ? $title : 'Badge',
                'image_url' => $imageUrl,
            ];
        }

        /** De-dupe by image URL within same event badge list */
        $seenUrls = [];

        /** @var list<array{badge_key: string, title: string, image_url: string}> $uniq */
        $uniq = [];
        foreach ($rows as $row) {
            $u = strtolower(trim($row['image_url']));
            if ($u === '' || isset($seenUrls[$u])) {
                continue;
            }
            $seenUrls[$u] = true;
            $uniq[] = $row;
        }

        return $uniq;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildJoinedChallengeEventsFromSnapshots(array $snapshots): array
    {
        $rows = [];

        foreach ($snapshots as $snap) {
            $event = $snap['event'];
            $logged = $snap['logged'];
            $goal = $snap['goal'];
            $percent = $snap['percent'];
            $pace = $snap['pace'];
            $targetLabel = $snap['targetLabel'];

            /** @var array<int, mixed>|mixed $badgeRaw */
            $badgeRaw = $event->badges;
            $badgesOut = [];
            if (is_array($badgeRaw)) {
                foreach ($badgeRaw as $b) {
                    if (is_string($b) && trim($b) !== '') {
                        $badgesOut[] = strtolower(trim($b));
                    }
                }
            }

            $challengeKm = Schema::hasColumn('admin_events', 'mileage_challenge_km')
                && $event->mileage_challenge_km !== null
                ? (float) $event->mileage_challenge_km
                : null;

            $rows[] = [
                'event_id' => (string) $event->id,
                'title' => (string) $event->title,
                'category' => strtolower((string) ($event->category ?? 'other')),
                'image_url' => $event->image_url ? (string) $event->image_url : null,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'joined_at' => $snap['reg_updated_at'] ?? null,
                'badges' => $badgesOut,
                'target_label' => $targetLabel,
                'mileage_challenge_km' => $challengeKm !== null && $challengeKm > 0 ? round($challengeKm, 4) : null,
                'progress_logged_km' => round((float) $logged, 2),
                'progress_goal_km' => $goal !== null ? round((float) $goal, 2) : null,
                'progress_percent' => $percent,
                'pace_min_per_km' => $pace,
                'submission_status' => (string) $snap['submission_status'],
                'pending_queue_km' => round((float) $snap['pending_queue_km'], 4),
                'pending_submissions_count' => (int) $snap['pending_submissions_count'],
            ];
        }

        return $rows;
    }

    /**
     * Snapshot of one registration + derived progress metrics (matches joined-event card payload logic).
     *
     * @return list<array{
     *     event: \App\Models\AdminEvent,
     *     logged: float,
     *     goal: float|null,
     *     pace: float|null,
     *     targetLabel: string|null,
     *     percent: float|null,
     *     reg_updated_at: string|null,
     *     submission_status: string,
     *     pending_queue_km: float,
     *     pending_submissions_count: int,
     *     client_id: string,
     * }>
     */
    private function challengeSnapshotsForClient(string $clientId, int $limit): array
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return [];
        }

        $progReady = Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');

        $regs = ClientAdminEventRegistration::query()
            ->where('client_id', $clientId)
            ->where(function ($q) {
                $q->where('registration_status', 'confirmed')
                    ->orWhere(function ($inner) {
                        $inner->where('registration_status', 'pending_payment')
                            ->where('payment_status', 'paid');
                    });
            })
            ->with(['event'])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $snapshots = [];

        foreach ($regs as $reg) {
            $event = $reg->event;
            if (! $event) {
                continue;
            }

            $goal = ($progReady && $reg->progress_goal_km !== null) ? (float) $reg->progress_goal_km : null;
            $logged = $progReady ? (float) ($reg->progress_logged_km ?? 0) : 0.0;
            $pace = ($progReady && $reg->progress_pace_min_per_km !== null) ? round((float) $reg->progress_pace_min_per_km, 4) : null;

            $targetLabel = $progReady && is_string($reg->progress_target_label) && trim($reg->progress_target_label) !== ''
                ? trim($reg->progress_target_label)
                : null;

            if (($goal === null || $goal <= 0) && strtolower((string) $event->category) === 'running' && Schema::hasTable('client_admin_event_running_selections')) {
                $sel = ClientAdminEventRunningSelection::query()
                    ->where('client_id', $clientId)
                    ->where('admin_event_id', $event->id)
                    ->first();
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
                    if ($targetLabel === null) {
                        $targetLabel = EventEnrollmentProgressService::runningTargetDisplay(
                            (string) $sel->distance_key,
                            $sel->distance_label !== null ? (string) $sel->distance_label : null
                        );
                    }
                }
            }

            $percent = null;
            if ($goal !== null && $goal > 0) {
                $pctRounded = min(100.0, round(($logged / $goal) * 100, 1));
                $percent = $logged + 1e-6 >= max(0.0, $goal - 0.08) ? 100.0 : $pctRounded;
            }

            $eventIdStr = (string) $event->id;
            $pendingKm = EventProgressSubmissionService::tableReady()
                ? EventProgressSubmissionService::sumPendingDeltaKm((string) $clientId, $eventIdStr)
                : 0.0;
            $pendingCt = EventProgressSubmissionService::tableReady()
                ? EventProgressSubmissionService::pendingCountForClientEvent((string) $clientId, $eventIdStr)
                : 0;

            $snapshots[] = [
                'event' => $event,
                'logged' => $logged,
                'goal' => $goal,
                'pace' => $pace,
                'targetLabel' => $targetLabel,
                'percent' => $percent,
                'reg_updated_at' => $reg->updated_at?->toISOString(),
                'submission_status' => $progReady ? (string) ($reg->progress_submission_status ?? 'none') : 'none',
                'pending_queue_km' => $pendingKm,
                'pending_submissions_count' => $pendingCt,
                'client_id' => $clientId,
            ];
        }

        return $snapshots;
    }

    private function calculateStreak(string $clientId): int
    {
        $streak = 0;
        $date = now()->toDateString();

        while (true) {
            $workout = WorkoutLog::where('client_id', $clientId)
                ->whereDate('workout_date', $date)
                ->where('status', 'completed')
                ->where('entry_type', 'workout')
                ->whereNotNull('distance_km')
                ->where('distance_km', '>', 0)
                ->first();

            if ($workout) {
                $streak++;
                $date = date('Y-m-d', strtotime($date . ' -1 day'));
            } else {
                break;
            }
        }

        return $streak;
    }
}
