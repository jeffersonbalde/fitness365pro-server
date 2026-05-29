<?php

namespace App\Support;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Services\EventEnrollmentProgressService;
use App\Services\EventProgressSubmissionService;
use Illuminate\Support\Facades\Schema;

/**
 * Challenge sidebar slice for authenticated viewers (CMS event cards, journals).
 */
final class ViewerChallengeProgressPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function slice(AdminEvent $event, ?ClientAdminEventRegistration $reg, string $clientId): ?array
    {
        return self::sliceInternal($event, $reg, $clientId, syncGoals: true);
    }

    /**
     * Read-only progress slice for list endpoints (no DB writes or refresh).
     *
     * @param  array<string, array{pending_km: float, pending_count: int}>|null  $pendingByEventId
     */
    public static function sliceReadOnly(
        AdminEvent $event,
        ?ClientAdminEventRegistration $reg,
        string $clientId,
        ?array $pendingByEventId = null,
    ): ?array {
        return self::sliceInternal($event, $reg, $clientId, syncGoals: false, pendingByEventId: $pendingByEventId);
    }

    /**
     * @param  array<string, array{pending_km: float, pending_count: int}>|null  $pendingByEventId
     */
    private static function sliceInternal(
        AdminEvent $event,
        ?ClientAdminEventRegistration $reg,
        string $clientId,
        bool $syncGoals,
        ?array $pendingByEventId = null,
    ): ?array {
        if (! $reg || strtolower((string) ($reg->registration_status ?? '')) !== 'confirmed') {
            return null;
        }
        if (! Schema::hasColumn($reg->getTable(), 'progress_logged_km')) {
            return null;
        }

        if ($syncGoals) {
            EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, $clientId);
            $reg->refresh();
        }

        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $logged = (float) ($reg->progress_logged_km ?? 0);
        /** @var float|null $percent */
        $percent = null;
        if ($goal !== null && $goal > 0.0) {
            $pctRounded = min(100.0, round(($logged / $goal) * 100, 1));
            // Approved total within ~80 m of goal still reads as finished (avoids stuck at 99.9% in UI CTAs).
            if ($logged + 1e-6 >= max(0.0, $goal - 0.08)) {
                $percent = 100.0;
            } else {
                $percent = $pctRounded;
            }
        }

        $challengeKm = Schema::hasColumn('admin_events', 'mileage_challenge_km')
            && $event->mileage_challenge_km !== null
            ? (float) $event->mileage_challenge_km
            : null;

        return [
            'logged_distance_km' => round($logged, 4),
            'goal_distance_km' => $goal !== null ? round($goal, 4) : null,
            'progress_percent' => $percent,
            'target_label' => $reg->progress_target_label ? (string) $reg->progress_target_label : null,
            'pace_min_per_km' => $reg->progress_pace_min_per_km !== null ? (float) $reg->progress_pace_min_per_km : null,
            'submission_status' => (string) ($reg->progress_submission_status ?? 'none'),
            'mileage_challenge_km' => ($challengeKm !== null && $challengeKm > 0) ? round($challengeKm, 4) : null,
            'pending_queue_km' => self::pendingKmForEvent($clientId, (string) $event->id, $pendingByEventId),
            'pending_submissions_count' => self::pendingCountForEvent($clientId, (string) $event->id, $pendingByEventId),
        ];
    }

    /**
     * @param  array<string, array{pending_km: float, pending_count: int}>|null  $pendingByEventId
     */
    private static function pendingKmForEvent(string $clientId, string $eventId, ?array $pendingByEventId): float
    {
        if ($pendingByEventId !== null) {
            return round((float) ($pendingByEventId[$eventId]['pending_km'] ?? 0), 4);
        }

        return EventProgressSubmissionService::tableReady()
            ? round(EventProgressSubmissionService::sumPendingDeltaKm($clientId, $eventId), 4)
            : 0.0;
    }

    /**
     * @param  array<string, array{pending_km: float, pending_count: int}>|null  $pendingByEventId
     */
    private static function pendingCountForEvent(string $clientId, string $eventId, ?array $pendingByEventId): int
    {
        if ($pendingByEventId !== null) {
            return (int) ($pendingByEventId[$eventId]['pending_count'] ?? 0);
        }

        return EventProgressSubmissionService::tableReady()
            ? EventProgressSubmissionService::pendingCountForClientEvent($clientId, $eventId)
            : 0;
    }

    /**
     * True when approved logged distance meets the configured challenge goal (incl. mileage_challenge fallback).
     */
    public static function distanceGoalIsSatisfied(?array $slice): bool
    {
        if ($slice === null) {
            return false;
        }

        $logged = (float) ($slice['logged_distance_km'] ?? 0);

        $goal = isset($slice['goal_distance_km']) && $slice['goal_distance_km'] !== null
            ? (float) $slice['goal_distance_km']
            : null;

        if ($goal === null || $goal <= 0.0) {
            $mc = isset($slice['mileage_challenge_km']) && $slice['mileage_challenge_km'] !== null
                ? (float) $slice['mileage_challenge_km']
                : null;
            $goal = ($mc !== null && $mc > 0.0) ? $mc : null;
        }

        if ($goal === null || $goal <= 0.0) {
            return false;
        }

        if ($logged + 1e-6 >= max(0.0, $goal - 0.051)) {
            return true;
        }

        $pct = $slice['progress_percent'] ?? null;

        return $pct !== null && (float) $pct >= 99.5;
    }
}
