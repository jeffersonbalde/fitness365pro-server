<?php

namespace App\Support;

use App\Models\EventProgressSubmission;
use App\Models\WorkoutLike;
use App\Models\WorkoutLog;
use App\Services\EventProgressSubmissionService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

/**
 * Client-facing workout payloads (feed, journals) — extracted for reuse outside WorkoutController.
 */
final class WorkoutJsonPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function serializeForClientViewer(WorkoutLog $workout, string $viewerId): array
    {
        $data = $workout->toArray();
        $data['workout_images'] = collect(is_array($data['workout_images'] ?? null) ? $data['workout_images'] : [])
            ->map(fn ($url) => self::resolveWorkoutImageUrl($url))
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->values()
            ->all();
        $data['likes_count'] = (int) ($workout->likes_count ?? 0);
        $data['comments_count'] = (int) ($workout->comments_count ?? 0);
        $data['is_liked_by_me'] = WorkoutLike::where('workout_log_id', $workout->id)
            ->where('client_id', $viewerId)
            ->exists();

        $data['linked_challenge'] = null;
        if (
            Schema::hasColumn($workout->getTable(), 'admin_event_id')
            && ! empty($workout->admin_event_id)
            && Schema::hasTable('admin_events')
        ) {
            $event = $workout->relationLoaded('linkedAdminEvent')
                ? $workout->getRelation('linkedAdminEvent')
                : $workout->linkedAdminEvent()->select('id', 'title')->first();
            if ($event) {
                $reviewStatus = 'none';
                if (EventProgressSubmissionService::tableReady()) {
                    $hasPending = EventProgressSubmission::query()
                        ->where('workout_log_id', (string) $workout->id)
                        ->where('status', EventProgressSubmission::STATUS_PENDING)
                        ->exists();
                    if ($hasPending) {
                        $reviewStatus = 'pending_review';
                    } elseif (
                        Schema::hasColumn($workout->getTable(), 'challenge_progress_approved_km')
                        && (float) ($workout->challenge_progress_approved_km ?? 0) > 0
                    ) {
                        $reviewStatus = 'applied';
                    } elseif (
                        EventProgressSubmission::query()
                            ->where('workout_log_id', (string) $workout->id)
                            ->where('status', EventProgressSubmission::STATUS_REJECTED)
                            ->exists()
                    ) {
                        $reviewStatus = 'rejected';
                    }
                }
                $data['linked_challenge'] = [
                    'id' => (string) $event->id,
                    'title' => (string) $event->title,
                    'review_status' => $reviewStatus,
                ];
            }
        }

        return $data;
    }

    public static function extractWorkoutPhotoRelativePath(?string $url): ?string
    {
        $trimmed = trim((string) $url);
        if ($trimmed === '') {
            return null;
        }

        $path = parse_url($trimmed, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            $path = $trimmed;
        }

        $path = ltrim($path, '/');
        $storagePrefix = 'storage/';
        $mediaPrefix = 'api/v1/profile/media/';

        if (str_starts_with($path, $storagePrefix)) {
            $path = substr($path, strlen($storagePrefix));
        } elseif (str_starts_with($path, $mediaPrefix)) {
            $path = substr($path, strlen($mediaPrefix));
        }

        $path = rawurldecode($path);

        return str_starts_with($path, 'workout-photos/') ? $path : null;
    }

    public static function resolveWorkoutImageUrl($url): string
    {
        if (! is_string($url) || trim($url) === '') {
            return '';
        }

        $relativePath = self::extractWorkoutPhotoRelativePath($url);
        if ($relativePath) {
            $encodedPath = collect(explode('/', $relativePath))
                ->map(fn ($segment) => rawurlencode($segment))
                ->implode('/');

            return URL::to("/api/v1/profile/media/{$encodedPath}");
        }

        return trim($url);
    }
}
