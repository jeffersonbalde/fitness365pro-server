<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Models\WorkoutLog;
use App\Support\ViewerChallengeProgressPresenter;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps CMS challenge mileage (client_admin_event_registrations.progress_*) aligned with
 * admin-approved submissions and workout-linked contributions (moderated queue).
 */
class ChallengeEnrollmentProgressService
{
    public static function registrationProgressCapKm(?float $goalKm): ?float
    {
        if ($goalKm === null || $goalKm <= 0) {
            return null;
        }

        return $goalKm * 5;
    }

    public static function paceFromWorkout(WorkoutLog $workout): ?float
    {
        $pace = $workout->pace_min_per_km !== null ? (float) $workout->pace_min_per_km : null;

        return ($pace !== null && $pace > 0) ? $pace : null;
    }

    /** Distance kilometers that contribute to enrollment progress for this workout row. */
    public static function distanceContributionKm(WorkoutLog $workout): float
    {
        $status = strtolower((string) ($workout->status ?? ''));
        if ($status !== 'completed') {
            return 0.0;
        }
        $entryType = strtolower((string) ($workout->entry_type ?? 'workout'));
        if ($entryType !== 'workout') {
            return 0.0;
        }

        return $workout->distance_km !== null ? (float) $workout->distance_km : 0.0;
    }

    public static function findConfirmedRegistration(string $clientId, string $adminEventId): ?ClientAdminEventRegistration
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return null;
        }

        $reg = ClientAdminEventRegistration::query()
            ->where('client_id', $clientId)
            ->where('admin_event_id', $adminEventId)
            ->first();

        if (! $reg || strtolower((string) $reg->registration_status) !== 'confirmed') {
            return null;
        }

        return $reg;
    }

    /**
     * Whether the viewer's approved distance for this enrolment satisfies the challenge goal.
     * Used to prevent attaching additional workouts once the distance goal is complete.
     */
    public static function challengeDistanceGoalIsComplete(string $clientId, string $adminEventId): bool
    {
        $reg = static::findConfirmedRegistration($clientId, $adminEventId);
        if ($reg === null) {
            return false;
        }

        $event = AdminEvent::query()->find($adminEventId);
        if ($event === null) {
            return false;
        }

        $slice = ViewerChallengeProgressPresenter::slice($event, $reg, $clientId);

        return ViewerChallengeProgressPresenter::distanceGoalIsSatisfied($slice);
    }

    /**
     * Applies km delta to enrollment totals (admin approval path only).
     */
    public static function applyDistanceDeltaOnly(
        ClientAdminEventRegistration $reg,
        float $deltaKm,
        ?float $optionalPaceMinPerKm = null,
    ): void {
        if (! Schema::hasColumn($reg->getTable(), 'progress_logged_km')) {
            return;
        }

        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $cap = static::registrationProgressCapKm($goal);
        $current = $reg->progress_logged_km !== null ? (float) $reg->progress_logged_km : 0.0;
        $next = round($current + $deltaKm, 4);

        if ($cap !== null) {
            $next = min($cap, $next);
            $next = max(0.0, $next);
        } elseif ($deltaKm < 0) {
            $next = max(0.0, $next);
        }

        $reg->progress_logged_km = round($next, 4);

        if ($optionalPaceMinPerKm !== null
            && $optionalPaceMinPerKm > 0
            && Schema::hasColumn($reg->getTable(), 'progress_pace_min_per_km')) {
            $reg->progress_pace_min_per_km = round($optionalPaceMinPerKm, 4);
        }

        $reg->save();
    }

    /** @deprecated Direct writes; use admin-approved submission flow. */
    public static function applyDistanceDelta(
        ClientAdminEventRegistration $reg,
        float $deltaKm,
        ?float $optionalPaceMinPerKm = null,
    ): void {
        static::applyDistanceDeltaOnly($reg, $deltaKm, $optionalPaceMinPerKm);
    }

    /**
     * Sets absolute logged total (admin approval of manual total correction).
     */
    public static function applyAbsoluteLoggedKmOnly(ClientAdminEventRegistration $reg, float $absoluteKm): void
    {
        if (! Schema::hasColumn($reg->getTable(), 'progress_logged_km')) {
            return;
        }

        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $cap = static::registrationProgressCapKm($goal);
        $v = round(max(0.0, $absoluteKm), 4);
        if ($cap !== null) {
            $v = min($cap, $v);
        }

        $reg->progress_logged_km = $v;
        $reg->save();
    }

    /** Legacy when submissions table is absent. */
    public static function legacyApplyContributionRemoval(
        string $clientId,
        ?string $adminEventId,
        WorkoutLog $snapshot,
    ): void {
        if (! $adminEventId || trim($adminEventId) === '') {
            return;
        }

        $reg = static::findConfirmedRegistration($clientId, $adminEventId);
        if (! $reg) {
            return;
        }

        $contrib = static::distanceContributionKm($snapshot);
        if ($contrib <= 0) {
            return;
        }

        static::applyDistanceDeltaOnly($reg, -$contrib);
    }

    public static function onWorkoutCreated(WorkoutLog $workout): void
    {
        EventProgressSubmissionService::afterWorkoutCreated($workout);
    }

    public static function syncEnrollmentForWorkoutChange(
        string $clientId,
        WorkoutLog $previous,
        WorkoutLog $current,
    ): void {
        EventProgressSubmissionService::afterWorkoutUpdated($previous, $current);
    }
}
