<?php

namespace App\Services;

use App\Models\ClientAdminEventRegistration;
use App\Models\EventProgressSubmission;
use App\Models\WorkoutLog;
use Illuminate\Support\Facades\Schema;

class EventProgressSubmissionService
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('event_progress_submissions');
    }

    public static function refreshRegistrationSubmissionStatus(
        string $clientId,
        string $adminEventId,
    ): void {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        $reg = ClientAdminEventRegistration::query()
            ->where('client_id', $clientId)
            ->where('admin_event_id', $adminEventId)
            ->first();

        if (! $reg || ! Schema::hasColumn($reg->getTable(), 'progress_submission_status')) {
            return;
        }

        $pending = EventProgressSubmission::query()
            ->where('client_id', $clientId)
            ->where('admin_event_id', $adminEventId)
            ->where('status', EventProgressSubmission::STATUS_PENDING)
            ->exists();

        $reg->progress_submission_status = $pending ? 'pending_review' : 'approved';
        $reg->save();
    }

    /**
     * After a new workout is stored (linked challenge).
     */
    public static function afterWorkoutCreated(WorkoutLog $workout): void
    {
        if (! static::tableReady()) {
            return;
        }

        $eventId = $workout->admin_event_id ? (string) $workout->admin_event_id : '';
        if ($eventId === '') {
            return;
        }

        $reg = ChallengeEnrollmentProgressService::findConfirmedRegistration((string) $workout->client_id, $eventId);
        if (! $reg) {
            return;
        }

        $km = ChallengeEnrollmentProgressService::distanceContributionKm($workout);
        if ($km <= 0) {
            return;
        }

        static::upsertWorkoutPendingSubmission($workout, $km, ChallengeEnrollmentProgressService::paceFromWorkout($workout));
    }

    /**
     * After workout updated: queue net contribution; revert cross-event moves immediately.
     */
    public static function afterWorkoutUpdated(WorkoutLog $prior, WorkoutLog $current): void
    {
        if (! static::tableReady()) {
            return;
        }

        $clientId = (string) $current->client_id;

        $oldEvent = $prior->admin_event_id ? (string) $prior->admin_event_id : null;
        $newEvent = $current->admin_event_id ? (string) $current->admin_event_id : null;

        if (($oldEvent ?: '') !== ($newEvent ?: '')) {
            static::deletePendingForWorkout((string) $current->id);

            $approvedOnPrior = static::approvedKmOnWorkout($prior);
            if ($oldEvent && $approvedOnPrior > 0) {
                $regOld = ChallengeEnrollmentProgressService::findConfirmedRegistration($clientId, $oldEvent);
                if ($regOld !== null) {
                    ChallengeEnrollmentProgressService::applyDistanceDeltaOnly($regOld, -$approvedOnPrior);
                }
            }

            $current->challenge_progress_approved_km = null;
            if (Schema::hasColumn($current->getTable(), 'challenge_progress_approved_km')) {
                WorkoutLog::query()->whereKey($current->id)->update(['challenge_progress_approved_km' => null]);
            }

            $newKm = ChallengeEnrollmentProgressService::distanceContributionKm($current);
            if ($newEvent && $newKm > 0) {
                static::createPendingSubmission(
                    $clientId,
                    $newEvent,
                    (string) $current->id,
                    EventProgressSubmission::SOURCE_WORKOUT,
                    $newKm,
                    ChallengeEnrollmentProgressService::paceFromWorkout($current),
                );
            }

            if ($oldEvent) {
                static::refreshRegistrationSubmissionStatus($clientId, $oldEvent);
            }
            if ($newEvent) {
                static::refreshRegistrationSubmissionStatus($clientId, $newEvent);
            }

            return;
        }

        $approvedBefore = static::approvedKmOnWorkout($prior);
        $newKm = ChallengeEnrollmentProgressService::distanceContributionKm($current);
        $pendingNet = round($newKm - $approvedBefore, 4);

        if (abs($pendingNet) < 0.0001) {
            static::deletePendingForWorkout((string) $current->id);
            if ($newEvent) {
                static::refreshRegistrationSubmissionStatus($clientId, $newEvent);
            }

            return;
        }

        static::upsertWorkoutPendingSubmission($current, $pendingNet, ChallengeEnrollmentProgressService::paceFromWorkout($current));

        if ($newEvent) {
            static::refreshRegistrationSubmissionStatus($clientId, $newEvent);
        }
    }

    /**
     * Before workout row removed: drop pending queue rows; subtract approved (or legacy counted) km from registration.
     */
    public static function beforeWorkoutDeleted(WorkoutLog $snapshot): void
    {
        if (! static::tableReady()) {
            ChallengeEnrollmentProgressService::legacyApplyContributionRemoval(
                (string) $snapshot->client_id,
                $snapshot->admin_event_id ? (string) $snapshot->admin_event_id : null,
                $snapshot,
            );

            return;
        }

        $eventId = $snapshot->admin_event_id ? (string) $snapshot->admin_event_id : null;
        $workoutId = (string) $snapshot->id;

        $hadPendingOnly = EventProgressSubmission::query()
            ->where('workout_log_id', $workoutId)
            ->where('status', EventProgressSubmission::STATUS_PENDING)
            ->exists();

        $approvedCol = static::approvedKmOnWorkout($snapshot);

        if ($approvedCol <= 0.0001 && $hadPendingOnly) {
            static::deletePendingForWorkout($workoutId);
            if ($eventId) {
                static::refreshRegistrationSubmissionStatus((string) $snapshot->client_id, $eventId);
            }

            return;
        }

        static::deletePendingForWorkout($workoutId);

        $toSubtract = $approvedCol > 0.0001
            ? $approvedCol
            : ChallengeEnrollmentProgressService::distanceContributionKm($snapshot);

        if ($toSubtract <= 0) {
            if ($eventId) {
                static::refreshRegistrationSubmissionStatus((string) $snapshot->client_id, $eventId);
            }

            return;
        }

        if (! $eventId) {
            return;
        }

        $reg = ChallengeEnrollmentProgressService::findConfirmedRegistration((string) $snapshot->client_id, $eventId);
        if ($reg !== null) {
            ChallengeEnrollmentProgressService::applyDistanceDeltaOnly($reg, -$toSubtract);
        }

        static::refreshRegistrationSubmissionStatus((string) $snapshot->client_id, $eventId);
    }

    public static function queueManualSubmission(
        ClientAdminEventRegistration $reg,
        float $distanceDeltaKm,
        ?float $paceMinPerKm,
    ): EventProgressSubmission {
        $submission = EventProgressSubmission::create([
            'client_id' => $reg->client_id,
            'admin_event_id' => (string) $reg->admin_event_id,
            'workout_log_id' => null,
            'source' => EventProgressSubmission::SOURCE_MANUAL,
            'distance_delta_km' => round($distanceDeltaKm, 4),
            'pace_min_per_km' => $paceMinPerKm !== null && $paceMinPerKm > 0 ? round((float) $paceMinPerKm, 4) : null,
            'status' => EventProgressSubmission::STATUS_PENDING,
        ]);

        static::refreshRegistrationSubmissionStatus((string) $reg->client_id, (string) $reg->admin_event_id);

        return $submission;
    }

    public static function approve(EventProgressSubmission $submission, string $adminId): void
    {
        if ($submission->status !== EventProgressSubmission::STATUS_PENDING) {
            return;
        }

        $reg = ChallengeEnrollmentProgressService::findConfirmedRegistration(
            (string) $submission->client_id,
            (string) $submission->admin_event_id,
        );

        if (! $reg) {
            return;
        }

        $delta = (float) $submission->distance_delta_km;
        $pace = $submission->pace_min_per_km !== null ? (float) $submission->pace_min_per_km : null;

        ChallengeEnrollmentProgressService::applyDistanceDeltaOnly($reg, $delta, $pace > 0 ? $pace : null);

        if ($submission->workout_log_id) {
            $w = WorkoutLog::query()->find($submission->workout_log_id);
            if ($w && Schema::hasColumn($w->getTable(), 'challenge_progress_approved_km')) {
                $prev = (float) ($w->challenge_progress_approved_km ?? 0);
                $w->challenge_progress_approved_km = round($prev + $delta, 4);
                $w->save();
            }
        }

        $submission->status = EventProgressSubmission::STATUS_APPROVED;
        $submission->reviewed_by = $adminId;
        $submission->reviewed_at = now();
        $submission->review_note = null;
        $submission->save();

        static::refreshRegistrationSubmissionStatus((string) $submission->client_id, (string) $submission->admin_event_id);

        $submission->loadMissing('event');
        ClientNotificationService::progressApproved($submission);
    }

    public static function reject(EventProgressSubmission $submission, string $adminId, string $note): void
    {
        if ($submission->status !== EventProgressSubmission::STATUS_PENDING) {
            return;
        }

        $submission->status = EventProgressSubmission::STATUS_REJECTED;
        $submission->reviewed_by = $adminId;
        $submission->reviewed_at = now();
        $submission->review_note = mb_substr(trim($note), 0, 600);
        $submission->save();

        static::refreshRegistrationSubmissionStatus((string) $submission->client_id, (string) $submission->admin_event_id);

        $submission->loadMissing('event');
        ClientNotificationService::progressRejected($submission, $note);
    }

    public static function sumPendingDeltaKm(string $clientId, string $adminEventId): float
    {
        if (! static::tableReady()) {
            return 0.0;
        }

        return (float) EventProgressSubmission::query()
            ->where('client_id', $clientId)
            ->where('admin_event_id', $adminEventId)
            ->where('status', EventProgressSubmission::STATUS_PENDING)
            ->sum('distance_delta_km');
    }

    public static function pendingCountForClientEvent(string $clientId, string $adminEventId): int
    {
        if (! static::tableReady()) {
            return 0;
        }

        return (int) EventProgressSubmission::query()
            ->where('client_id', $clientId)
            ->where('admin_event_id', $adminEventId)
            ->where('status', EventProgressSubmission::STATUS_PENDING)
            ->count();
    }

    protected static function approvedKmOnWorkout(WorkoutLog $w): float
    {
        if (! Schema::hasColumn($w->getTable(), 'challenge_progress_approved_km')) {
            return 0.0;
        }

        $v = $w->challenge_progress_approved_km;

        return $v !== null ? (float) $v : 0.0;
    }

    protected static function deletePendingForWorkout(string $workoutLogId): void
    {
        if (! static::tableReady()) {
            return;
        }

        EventProgressSubmission::query()
            ->where('workout_log_id', $workoutLogId)
            ->where('status', EventProgressSubmission::STATUS_PENDING)
            ->delete();
    }

    protected static function upsertWorkoutPendingSubmission(WorkoutLog $workout, float $pendingNetKm, ?float $pace): void
    {
        static::deletePendingForWorkout((string) $workout->id);

        $eventId = $workout->admin_event_id ? (string) $workout->admin_event_id : '';
        if ($eventId === '') {
            return;
        }

        static::createPendingSubmission(
            (string) $workout->client_id,
            $eventId,
            (string) $workout->id,
            EventProgressSubmission::SOURCE_WORKOUT,
            $pendingNetKm,
            $pace,
        );
    }

    protected static function createPendingSubmission(
        string $clientId,
        string $adminEventId,
        ?string $workoutLogId,
        string $source,
        float $distanceDeltaKm,
        ?float $pace,
    ): void {
        EventProgressSubmission::create([
            'client_id' => $clientId,
            'admin_event_id' => $adminEventId,
            'workout_log_id' => $workoutLogId,
            'source' => $source,
            'distance_delta_km' => round($distanceDeltaKm, 4),
            'pace_min_per_km' => $pace !== null && $pace > 0 ? round($pace, 4) : null,
            'status' => EventProgressSubmission::STATUS_PENDING,
        ]);
    }
}
