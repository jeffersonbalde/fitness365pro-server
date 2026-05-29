<?php

namespace App\Support;

use App\Models\EventProgressSubmission;
use App\Models\WorkoutLike;
use App\Models\WorkoutLog;
use App\Services\EventProgressSubmissionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Client-facing workout payloads (feed, journals) — extracted for reuse outside WorkoutController.
 */
final class WorkoutJsonPresenter
{
    private static ?bool $hasAdminEventIdColumn = null;

    private static ?bool $hasChallengeProgressColumn = null;

    private static function hasAdminEventIdColumn(): bool
    {
        if (self::$hasAdminEventIdColumn === null) {
            self::$hasAdminEventIdColumn = Schema::hasColumn((new WorkoutLog)->getTable(), 'admin_event_id');
        }

        return self::$hasAdminEventIdColumn;
    }

    private static function hasChallengeProgressColumn(): bool
    {
        if (self::$hasChallengeProgressColumn === null) {
            self::$hasChallengeProgressColumn = Schema::hasColumn((new WorkoutLog)->getTable(), 'challenge_progress_approved_km');
        }

        return self::$hasChallengeProgressColumn;
    }
    /**
     * @param  Collection<int, WorkoutLog>|array<int, WorkoutLog>  $workouts
     * @return array<int, array<string, mixed>>
     */
    public static function serializeManyForClientViewer(Collection|array $workouts, string $viewerId): array
    {
        $workouts = collect($workouts)->values();
        if ($workouts->isEmpty()) {
            return [];
        }

        $workoutIds = $workouts->pluck('id')->map(fn ($id) => (string) $id)->all();

        $likedByViewer = WorkoutLike::query()
            ->where('client_id', $viewerId)
            ->whereIn('workout_log_id', $workoutIds)
            ->pluck('workout_log_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();

        $challengeReviewByWorkout = self::preloadChallengeReviewStatuses($workouts);

        return $workouts
            ->map(fn (WorkoutLog $workout) => self::serializeForClientViewer(
                $workout,
                $viewerId,
                $likedByViewer,
                $challengeReviewByWorkout,
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeForClientViewer(
        WorkoutLog $workout,
        string $viewerId,
        ?array $likedByViewer = null,
        ?array $challengeReviewByWorkout = null,
    ): array {
        $data = $workout->toArray();
        $data['workout_images'] = collect(is_array($data['workout_images'] ?? null) ? $data['workout_images'] : [])
            ->map(fn ($url) => self::resolveWorkoutImageUrl($url))
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->values()
            ->all();
        $data['likes_count'] = (int) ($workout->likes_count ?? 0);
        $data['comments_count'] = (int) ($workout->comments_count ?? 0);

        if ($likedByViewer !== null) {
            $data['is_liked_by_me'] = isset($likedByViewer[(string) $workout->id]);
        } else {
            $data['is_liked_by_me'] = WorkoutLike::where('workout_log_id', $workout->id)
                ->where('client_id', $viewerId)
                ->exists();
        }

        $data['linked_challenge'] = null;
        if (
            self::hasAdminEventIdColumn()
            && ! empty($workout->admin_event_id)
            && Schema::hasTable('admin_events')
        ) {
            $event = $workout->relationLoaded('linkedAdminEvent')
                ? $workout->getRelation('linkedAdminEvent')
                : $workout->linkedAdminEvent()->select('id', 'title')->first();
            if ($event) {
                $reviewStatus = self::resolveChallengeReviewStatus(
                    $workout,
                    $challengeReviewByWorkout,
                );
                $data['linked_challenge'] = [
                    'id' => (string) $event->id,
                    'title' => (string) $event->title,
                    'review_status' => $reviewStatus,
                ];
            }
        }

        return $data;
    }

    /**
     * @param  Collection<int, WorkoutLog>  $workouts
     * @return array<string, string>
     */
    private static function preloadChallengeReviewStatuses(Collection $workouts): array
    {
        if (! EventProgressSubmissionService::tableReady()) {
            return [];
        }

        $linkedIds = $workouts
            ->filter(function (WorkoutLog $workout) {
                return self::hasAdminEventIdColumn() && ! empty($workout->admin_event_id);
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($linkedIds === []) {
            return [];
        }

        $statuses = [];
        $submissions = EventProgressSubmission::query()
            ->whereIn('workout_log_id', $linkedIds)
            ->whereIn('status', [
                EventProgressSubmission::STATUS_PENDING,
                EventProgressSubmission::STATUS_REJECTED,
            ])
            ->get(['workout_log_id', 'status']);

        foreach ($submissions as $submission) {
            $workoutId = (string) $submission->workout_log_id;
            if ($submission->status === EventProgressSubmission::STATUS_PENDING) {
                $statuses[$workoutId] = 'pending_review';

                continue;
            }

            if (($statuses[$workoutId] ?? null) !== 'pending_review') {
                $statuses[$workoutId] = 'rejected';
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, string>|null  $challengeReviewByWorkout
     */
    private static function resolveChallengeReviewStatus(
        WorkoutLog $workout,
        ?array $challengeReviewByWorkout,
    ): string {
        $workoutId = (string) $workout->id;

        if ($challengeReviewByWorkout !== null) {
            if (($challengeReviewByWorkout[$workoutId] ?? null) === 'pending_review') {
                return 'pending_review';
            }

            if (
                self::hasChallengeProgressColumn()
                && (float) ($workout->challenge_progress_approved_km ?? 0) > 0
            ) {
                return 'applied';
            }

            if (($challengeReviewByWorkout[$workoutId] ?? null) === 'rejected') {
                return 'rejected';
            }

            return 'none';
        }

        if (EventProgressSubmissionService::tableReady()) {
            $hasPending = EventProgressSubmission::query()
                ->where('workout_log_id', $workoutId)
                ->where('status', EventProgressSubmission::STATUS_PENDING)
                ->exists();
            if ($hasPending) {
                return 'pending_review';
            }

            if (
                self::hasChallengeProgressColumn()
                && (float) ($workout->challenge_progress_approved_km ?? 0) > 0
            ) {
                return 'applied';
            }

            if (
                EventProgressSubmission::query()
                    ->where('workout_log_id', $workoutId)
                    ->where('status', EventProgressSubmission::STATUS_REJECTED)
                    ->exists()
            ) {
                return 'rejected';
            }
        }

        return 'none';
    }

    public static function extractWorkoutPhotoRelativePath(?string $url): ?string
    {
        return PublicUploadStorage::extractRelativePath($url, ['workout-photos']);
    }

    public static function resolveWorkoutImageUrl($url): string
    {
        return PublicUploadStorage::resolveForClient(is_string($url) ? $url : '');
    }
}
