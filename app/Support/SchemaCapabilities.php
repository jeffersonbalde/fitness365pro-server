<?php

namespace App\Support;

use App\Models\ClientAdminEventRegistration;
use App\Models\WorkoutLog;
use Illuminate\Support\Facades\Schema;

/**
 * Memoized schema checks for hot API paths (avoids repeated information_schema lookups).
 */
final class SchemaCapabilities
{
    private static ?bool $registrationsTable = null;

    private static ?bool $regProgressLoggedKm = null;

    private static ?bool $regRegistrationStatus = null;

    private static ?bool $regProgressSubmissionStatus = null;

    private static ?bool $regProgressGoalCompletedAt = null;

    private static ?bool $runningSelectionsTable = null;

    private static ?bool $adminPostsTable = null;

    private static ?bool $adminEventsMileageChallenge = null;

    private static ?bool $workoutAdminEventId = null;

    private static ?bool $clientFollowsTable = null;

    public static function hasRegistrationsTable(): bool
    {
        return self::$registrationsTable ??= Schema::hasTable('client_admin_event_registrations');
    }

    public static function hasRegProgressLoggedKm(): bool
    {
        return self::$regProgressLoggedKm ??= self::hasRegistrationsTable()
            && Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');
    }

    public static function hasRegRegistrationStatus(): bool
    {
        return self::$regRegistrationStatus ??= self::hasRegistrationsTable()
            && Schema::hasColumn('client_admin_event_registrations', 'registration_status');
    }

    public static function hasRegProgressSubmissionStatus(): bool
    {
        return self::$regProgressSubmissionStatus ??= self::hasRegistrationsTable()
            && Schema::hasColumn('client_admin_event_registrations', 'progress_submission_status');
    }

    public static function hasRegProgressGoalCompletedAt(): bool
    {
        return self::$regProgressGoalCompletedAt ??= self::hasRegistrationsTable()
            && Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at');
    }

    public static function hasRunningSelectionsTable(): bool
    {
        return self::$runningSelectionsTable ??= Schema::hasTable('client_admin_event_running_selections');
    }

    public static function hasAdminPostsTable(): bool
    {
        return self::$adminPostsTable ??= Schema::hasTable('admin_posts');
    }

    public static function hasAdminEventsMileageChallenge(): bool
    {
        return self::$adminEventsMileageChallenge ??= Schema::hasTable('admin_events')
            && Schema::hasColumn('admin_events', 'mileage_challenge_km');
    }

    public static function hasWorkoutAdminEventId(): bool
    {
        return self::$workoutAdminEventId ??= Schema::hasColumn((new WorkoutLog)->getTable(), 'admin_event_id');
    }

    public static function hasClientFollowsTable(): bool
    {
        return self::$clientFollowsTable ??= Schema::hasTable('client_follows');
    }

    public static function registrationTable(): string
    {
        return (new ClientAdminEventRegistration)->getTable();
    }
}
