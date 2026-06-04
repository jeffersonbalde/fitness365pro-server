<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\CommunityMember;
use App\Models\WorkoutLog;
use App\Support\PublicUploadStorage;
use Illuminate\Support\Facades\Schema;

class WorkoutStatsService
{
    public function __construct(
        private readonly EventFinisherRankService $finisherRankService,
    ) {}

    private static ?bool $registrationsTableReady = null;

    private static ?bool $trophyAwardModeColumnReady = null;

    private static ?bool $progressColumnReady = null;

    private static ?bool $runningSelectionsTableReady = null;

    private static ?bool $mileageChallengeColumnReady = null;

    /**
     * Same shape as WorkoutController::stats "data" payload (no HTTP wrapper).
     */
    public function buildPayloadForClient(string $clientId): array
    {
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        $aggregates = WorkoutLog::query()
            ->where('client_id', $clientId)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) as total_workouts')
            ->selectRaw(
                'SUM(CASE WHEN workout_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_week',
                [$weekStart, $weekEnd]
            )
            ->selectRaw(
                'SUM(CASE WHEN entry_type = ? AND distance_km IS NOT NULL AND distance_km > 0 THEN 1 ELSE 0 END) as total_runs',
                ['workout']
            )
            ->selectRaw(
                'SUM(CASE WHEN entry_type = ? AND distance_km IS NOT NULL AND distance_km > 0 THEN distance_km ELSE 0 END) as total_distance_km',
                ['workout']
            )
            ->selectRaw(
                'SUM(CASE WHEN entry_type = ? AND distance_km IS NOT NULL AND distance_km > 0 AND pace_min_per_km IS NOT NULL THEN pace_min_per_km * distance_km ELSE 0 END) as weighted_pace_sum',
                ['workout']
            )
            ->selectRaw(
                'SUM(CASE WHEN entry_type = ? AND distance_km IS NOT NULL AND distance_km > 0 AND pace_min_per_km IS NOT NULL THEN distance_km ELSE 0 END) as pace_distance_sum',
                ['workout']
            )
            ->first();

        $totalWorkouts = (int) ($aggregates->total_workouts ?? 0);
        $thisWeek = (int) ($aggregates->this_week ?? 0);
        $totalRuns = (int) ($aggregates->total_runs ?? 0);
        $totalDistance = (float) ($aggregates->total_distance_km ?? 0);
        $paceDistanceSum = (float) ($aggregates->pace_distance_sum ?? 0);
        $weightedPaceSum = (float) ($aggregates->weighted_pace_sum ?? 0);

        $avgPace = null;
        if ($paceDistanceSum > 0) {
            $avgPace = round($weightedPaceSum / $paceDistanceSum, 2);
        }

        $snapshots = $this->challengeSnapshotsForClient($clientId, 48);

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
                if (! $community) {
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

        $payload = [
            'total_workouts' => $totalWorkouts,
            'this_week' => $thisWeek,
            'current_streak' => $this->calculateStreak($clientId),
            'total_distance_km' => round($totalDistance, 2),
            'total_runs' => $totalRuns,
            'avg_pace_min_per_km' => $avgPace,
            'badges' => [],
            'trophies' => [],
            'event_badges' => $this->buildEarnedEventBadgesFromSnapshots($snapshots),
            'event_trophies' => $this->buildEarnedEventTrophiesFromSnapshots($snapshots),
            'joined_races' => $joinedRaces,
            'joined_challenge_events' => $this->buildJoinedChallengeEventsFromSnapshots(
                array_slice($snapshots, 0, 12)
            ),
        ];

        // Replace earned badge/trophy images with personalized versions (name overlay).
        $payload['event_badges'] = $this->attachPersonalizedRewardUrls($clientId, (array) ($payload['event_badges'] ?? []), 'badge');
        $payload['event_trophies'] = $this->attachPersonalizedRewardUrls($clientId, (array) ($payload['event_trophies'] ?? []), 'trophy');

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachPersonalizedRewardUrls(string $clientId, array $rows, string $kind): array
    {
        $kind = $kind === 'trophy' ? 'trophy' : 'badge';
        $ext = $this->personalizedRewardExtension();

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $eventId = (string) ($row['event_id'] ?? '');
            $key = $kind === 'trophy'
                ? (string) ($row['trophy_key'] ?? '')
                : (string) ($row['badge_key'] ?? '');

            if ($eventId === '' || trim($key) === '') {
                $out[] = $row;
                continue;
            }

            // Keep the original artwork URL for the renderer (normalized for client media proxy).
            $row['base_image_url'] = PublicUploadStorage::resolveForClient((string) ($row['image_url'] ?? ''));

            // Public, no-auth endpoint. Starts with "/" so client resolveMediaUrl prefixes API origin.
            $row['image_url'] = sprintf(
                '/share/reward/%s/%s/%s/%s.%s',
                rawurlencode($clientId),
                rawurlencode($eventId),
                $kind,
                rawurlencode($key),
                $ext,
            );

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array{id: string, event_id: string, event_title: string, badge_key: string, title: string, image_url: string}>
     */
    private function buildEarnedEventBadgesFromSnapshots(array $snapshots): array
    {
        if (! $this->registrationsTableReady()) {
            return [];
        }

        $out = [];
        $seenFingerprints = [];

        foreach ($snapshots as $snap) {
            $pct = $snap['percent'];
            if ($pct === null || abs((float) $pct - 100.0) > 1e-3) {
                continue;
            }
            $event = $snap['event'];
            foreach ($this->rewardImageCatalogFromEventRaw($event->badges ?? null, 'badge') as $badge) {
                $fp = (string) $event->id.'|'.$badge['reward_key'].'|'.$badge['image_url'];
                if (isset($seenFingerprints[$fp])) {
                    continue;
                }
                $seenFingerprints[$fp] = true;

                $safeEvent = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $event->id);
                $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $badge['reward_key']);

                $out[] = [
                    'id' => 'eb_'.$safeEvent.'_'.$safeKey,
                    'event_id' => (string) $event->id,
                    'event_title' => (string) ($event->title ?? 'Event'),
                    'badge_key' => $badge['reward_key'],
                    'title' => $badge['title'],
                    'image_url' => $badge['image_url'],
                    'earned_at' => $snap['reg_updated_at'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array{id: string, event_id: string, event_title: string, trophy_key: string, title: string, image_url: string, earned_at: string|null}>
     */
    private function buildEarnedEventTrophiesFromSnapshots(array $snapshots): array
    {
        if (! $this->registrationsTableReady()) {
            return [];
        }

        $out = [];
        $seenFingerprints = [];

        foreach ($snapshots as $snap) {
            $pct = $snap['percent'];
            if ($pct === null || abs((float) $pct - 100.0) > 1e-3) {
                continue;
            }
            $event = $snap['event'];
            if (! $this->clientQualifiesForEventTrophies($event, $snap)) {
                continue;
            }
            foreach ($this->rewardImageCatalogFromEventRaw($event->trophies ?? null, 'trophy') as $trophy) {
                $fp = (string) $event->id.'|'.$trophy['reward_key'].'|'.$trophy['image_url'];
                if (isset($seenFingerprints[$fp])) {
                    continue;
                }
                $seenFingerprints[$fp] = true;

                $safeEvent = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $event->id);
                $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $trophy['reward_key']);

                $out[] = [
                    'id' => 'et_'.$safeEvent.'_'.$safeKey,
                    'event_id' => (string) $event->id,
                    'event_title' => (string) ($event->title ?? 'Event'),
                    'trophy_key' => $trophy['reward_key'],
                    'title' => $trophy['title'],
                    'image_url' => $trophy['image_url'],
                    'earned_at' => $snap['reg_updated_at'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function clientQualifiesForEventTrophies(AdminEvent $event, array $snap): bool
    {
        if (! $this->trophyAwardModeColumnReady()) {
            return true;
        }

        $mode = strtolower(trim((string) ($event->trophy_award_mode ?? 'all_finishers')));
        if ($mode === '' || $mode === 'all_finishers') {
            return true;
        }

        if ($mode !== 'top_n') {
            return true;
        }

        $topN = max(1, min(100, (int) ($event->trophy_top_n ?? 10)));
        $clientId = (string) ($snap['client_id'] ?? '');
        if ($clientId === '') {
            return false;
        }

        $rank = $this->finisherRankService->finisherRankForClient((string) $event->id, $clientId);

        return $rank !== null && $rank <= $topN;
    }

    /**
     * Resolve a single earned badge for public share cards.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublicBadgeShare(string $clientId, string $eventId, string $badgeKey): ?array
    {
        $normalizedKey = $this->normalizeBadgeKeyForLookup($badgeKey);
        $ext = $this->personalizedRewardExtension();

        foreach ($this->buildEarnedEventBadgesFromSnapshots($this->challengeSnapshotsForClient($clientId, 48)) as $badge) {
            if ((string) ($badge['event_id'] ?? '') !== (string) $eventId) {
                continue;
            }

            $candidateKey = $this->normalizeBadgeKeyForLookup((string) ($badge['badge_key'] ?? ''));
            if ($candidateKey !== $normalizedKey) {
                continue;
            }

            $badge['base_image_url'] = (string) ($badge['image_url'] ?? '');
            $badge['image_url'] = sprintf(
                '/share/reward/%s/%s/badge/%s.%s',
                rawurlencode($clientId),
                rawurlencode((string) $eventId),
                rawurlencode((string) ($badge['badge_key'] ?? $badgeKey)),
                $ext,
            );

            return $badge;
        }

        return null;
    }

    /**
     * Resolve a single earned trophy for public share cards.
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublicTrophyShare(string $clientId, string $eventId, string $trophyKey): ?array
    {
        $normalizedKey = $this->normalizeBadgeKeyForLookup($trophyKey);
        $ext = $this->personalizedRewardExtension();

        foreach ($this->buildEarnedEventTrophiesFromSnapshots($this->challengeSnapshotsForClient($clientId, 48)) as $trophy) {
            if ((string) ($trophy['event_id'] ?? '') !== (string) $eventId) {
                continue;
            }

            $candidateKey = $this->normalizeBadgeKeyForLookup((string) ($trophy['trophy_key'] ?? ''));
            if ($candidateKey !== $normalizedKey) {
                continue;
            }

            $trophy['base_image_url'] = (string) ($trophy['image_url'] ?? '');
            $trophy['image_url'] = sprintf(
                '/share/reward/%s/%s/trophy/%s.%s',
                rawurlencode($clientId),
                rawurlencode((string) $eventId),
                rawurlencode((string) ($trophy['trophy_key'] ?? $trophyKey)),
                $ext,
            );

            return $trophy;
        }

        return null;
    }

    private function personalizedRewardExtension(): string
    {
        // If image libs are missing (common in XAMPP), use SVG overlay so the name still shows.
        if (function_exists('imagecreatefromstring') || class_exists(\Imagick::class)) {
            return 'png';
        }

        return 'svg';
    }

    private function normalizeBadgeKeyForLookup(string $badgeKey): string
    {
        $decoded = rawurldecode(trim($badgeKey));
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $decoded);

        return strtolower((string) $safe);
    }

    /**
     * @param  mixed  $raw  admin_events.badges or admin_events.trophies JSON
     * @return list<array{reward_key: string, title: string, image_url: string}>
     */
    private function rewardImageCatalogFromEventRaw(mixed $raw, string $keyPrefix = 'badge'): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $prefix = $keyPrefix === 'trophy' ? 'trophy' : 'badge';
        $defaultTitle = $prefix === 'trophy' ? 'Trophy' : 'Badge';
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
            $key = $slug !== '' ? strtolower($slug) : $prefix.'_'.(is_int($i) ? $i + 1 : md5($imageUrl.'|'.$title));

            $rows[] = [
                'reward_key' => $key,
                'title' => $title !== '' ? $title : $defaultTitle,
                'image_url' => $imageUrl,
            ];
        }

        $seenUrls = [];
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

            $badgeRaw = $event->badges;
            $badgesOut = [];
            if (is_array($badgeRaw)) {
                foreach ($badgeRaw as $b) {
                    if (is_string($b) && trim($b) !== '') {
                        $badgesOut[] = strtolower(trim($b));
                    }
                }
            }

            $challengeKm = $this->mileageChallengeColumnReady()
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
     * @return list<array<string, mixed>>
     */
    private function challengeSnapshotsForClient(string $clientId, int $limit): array
    {
        if (! $this->registrationsTableReady()) {
            return [];
        }

        $progReady = $this->progressColumnReady();

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

        if ($regs->isEmpty()) {
            return [];
        }

        $eventIds = $regs->pluck('admin_event_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
        $pendingByEventId = EventProgressSubmissionService::pendingSummaryForClientEvents($clientId, $eventIds);

        $selectionsByEventId = collect();
        if ($this->runningSelectionsTableReady()) {
            $selectionsByEventId = ClientAdminEventRunningSelection::query()
                ->where('client_id', $clientId)
                ->whereIn('admin_event_id', $eventIds)
                ->get()
                ->keyBy(static fn ($row) => (string) $row->admin_event_id);
        }

        $snapshots = [];

        foreach ($regs as $reg) {
            $event = $reg->event;
            if (! $event) {
                continue;
            }

            $goal = ($progReady && $reg->progress_goal_km !== null) ? (float) $reg->progress_goal_km : null;
            $logged = $progReady ? (float) ($reg->progress_logged_km ?? 0) : 0.0;
            $pace = ($progReady && $reg->progress_pace_min_per_km !== null)
                ? round((float) $reg->progress_pace_min_per_km, 4)
                : null;

            $targetLabel = $progReady && is_string($reg->progress_target_label) && trim($reg->progress_target_label) !== ''
                ? trim($reg->progress_target_label)
                : null;

            if (($goal === null || $goal <= 0)
                && strtolower((string) $event->category) === 'running') {
                $sel = $selectionsByEventId->get((string) $event->id);
                if ($sel) {
                    $eventChallenge = $this->mileageChallengeColumnReady()
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
            $pending = $pendingByEventId[$eventIdStr] ?? ['pending_km' => 0.0, 'pending_count' => 0];

            $snapshots[] = [
                'event' => $event,
                'logged' => $logged,
                'goal' => $goal,
                'pace' => $pace,
                'targetLabel' => $targetLabel,
                'percent' => $percent,
                'reg_updated_at' => $reg->updated_at?->toISOString(),
                'submission_status' => $progReady ? (string) ($reg->progress_submission_status ?? 'none') : 'none',
                'pending_queue_km' => (float) $pending['pending_km'],
                'pending_submissions_count' => (int) $pending['pending_count'],
                'client_id' => $clientId,
            ];
        }

        return $snapshots;
    }

    private function calculateStreak(string $clientId): int
    {
        $dates = WorkoutLog::query()
            ->where('client_id', $clientId)
            ->where('status', 'completed')
            ->where('entry_type', 'workout')
            ->whereNotNull('distance_km')
            ->where('distance_km', '>', 0)
            ->orderByDesc('workout_date')
            ->limit(400)
            ->pluck('workout_date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->unique()
            ->values()
            ->all();

        if ($dates === []) {
            return 0;
        }

        $dateSet = array_fill_keys($dates, true);
        $streak = 0;
        $cursor = now()->toDateString();

        if (! isset($dateSet[$cursor])) {
            $cursor = now()->subDay()->toDateString();
        }

        while (isset($dateSet[$cursor])) {
            $streak++;
            $cursor = date('Y-m-d', strtotime($cursor.' -1 day'));
        }

        return $streak;
    }

    private function registrationsTableReady(): bool
    {
        return self::$registrationsTableReady ??= Schema::hasTable('client_admin_event_registrations');
    }

    private function progressColumnReady(): bool
    {
        return self::$progressColumnReady ??= Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');
    }

    private function runningSelectionsTableReady(): bool
    {
        return self::$runningSelectionsTableReady ??= Schema::hasTable('client_admin_event_running_selections');
    }

    private function mileageChallengeColumnReady(): bool
    {
        return self::$mileageChallengeColumnReady ??= Schema::hasColumn('admin_events', 'mileage_challenge_km');
    }

    private function trophyAwardModeColumnReady(): bool
    {
        return self::$trophyAwardModeColumnReady ??= Schema::hasColumn('admin_events', 'trophy_award_mode');
    }
}
