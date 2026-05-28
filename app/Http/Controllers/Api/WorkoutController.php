<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WorkoutComment;
use App\Models\WorkoutCommentLike;
use App\Models\WorkoutLog;
use App\Models\WorkoutLike;
use App\Services\ChallengeEnrollmentProgressService;
use App\Services\ClientNotificationService;
use App\Services\EventProgressSubmissionService;
use App\Services\Social\FeedRankingService;
use App\Services\WorkoutStatsService;
use App\Rules\ValidWorkoutImage;
use App\Support\WorkoutJsonPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class WorkoutController extends Controller
{
    public function __construct(
        private readonly FeedRankingService $feedRankingService,
        private readonly WorkoutStatsService $workoutStatsService,
    ) {
    }

    private function mapClientSummary(Client $client, ?Client $viewer = null): array
    {
        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        if (!$displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'User';
        }

        $mapped = [
            'id' => $client->id,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
            'city' => $profile?->city,
            'province' => $profile?->province,
        ];

        if ($viewer) {
            $mapped['is_self'] = $viewer->id === $client->id;
            $mapped['is_following'] = $viewer->id === $client->id
                ? false
                : $viewer->following()->where('clients.id', $client->id)->exists();
        }

        return $mapped;
    }

    private function serializeWorkout(WorkoutLog $workout, string $viewerId): array
    {
        return WorkoutJsonPresenter::serializeForClientViewer($workout, $viewerId);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WorkoutLog>|\Illuminate\Database\Eloquent\Collection<int, WorkoutLog>  $workouts
     * @return array<int, array<string, mixed>>
     */
    private function serializeWorkouts($workouts, string $viewerId): array
    {
        return WorkoutJsonPresenter::serializeManyForClientViewer($workouts, $viewerId);
    }

    private function serializeComment(WorkoutComment $comment, string $viewerId, bool $includeReplies = true): array
    {
        $mapped = [
            'id' => $comment->id,
            'workout_log_id' => $comment->workout_log_id,
            'parent_comment_id' => $comment->parent_comment_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'author' => $this->mapClientSummary($comment->client),
            'likes_count' => (int) ($comment->likes_count ?? 0),
            'is_liked_by_me' => WorkoutCommentLike::where('workout_comment_id', $comment->id)
                ->where('client_id', $viewerId)
                ->exists(),
        ];

        if ($includeReplies) {
            $mapped['replies'] = $comment->replies
                ->map(fn (WorkoutComment $reply) => $this->serializeComment($reply, $viewerId, false))
                ->values();
        }

        return $mapped;
    }

    private function parseImageListInput(Request $request, string $key): array
    {
        $value = $request->input($key, []);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return collect(is_array($value) ? $value : [])
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim($url))
            ->values()
            ->all();
    }

    private function deleteWorkoutPhotoByUrl(?string $url): void
    {
        if (!$url || !is_string($url)) {
            return;
        }

        $relativePath = WorkoutJsonPresenter::extractWorkoutPhotoRelativePath($url);
        if (!$relativePath) {
            return;
        }

        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function validateWorkout(Request $request, bool $isUpdate = false, ?WorkoutLog $existingWorkout = null)
    {
        $entryType = (string) $request->input('entry_type', $existingWorkout?->entry_type ?? 'workout');
        $isPostEntry = $entryType === 'post';

        $rules = [
            'entry_type' => 'nullable|in:workout,post',
            'workout_type' => [$isPostEntry ? 'nullable' : 'required', 'string', 'max:100'],
            'workout_date' => 'nullable|date|before_or_equal:today',
            'duration_minutes' => [$isPostEntry ? 'nullable' : 'required', 'integer', 'min:1'],
            'distance_km' => [$isPostEntry ? 'nullable' : 'required', 'numeric', 'gt:0'],
            'duration_seconds' => 'nullable|integer|min:1',
            'status' => 'nullable|in:completed,skipped,partial',
            'notes' => 'nullable|string|max:1000',
            'caption' => 'nullable|string|max:2200',
            'location' => 'nullable|string|max:255',
            'workout_images' => 'nullable|array',
            'workout_images.*' => ['required', new ValidWorkoutImage()],
            'replace_images' => 'nullable|boolean',
            'keep_workout_images' => 'nullable|array',
            'keep_workout_images.*' => 'string',
            'plan_day' => 'nullable|integer|min:1',
            'admin_event_id' => 'nullable|string|uuid',
        ];

        $validator = Validator::make($request->all(), $rules);
        $viewer = $request->user();
        $validator->after(function ($validator) use ($request, $isPostEntry, $existingWorkout, $viewer, $isUpdate) {
            if (! $viewer) {
                return;
            }
            if (!$request->filled('workout_date') && !$existingWorkout?->workout_date) {
                $validator->errors()->add('workout_date', 'Workout date is required.');
            }

            if ($isPostEntry) {
                $caption = trim((string) $request->input('caption', ''));
                $notes = trim((string) $request->input('notes', ''));
                $hasImages = $request->hasFile('workout_images')
                    || (is_array($existingWorkout?->workout_images) && count($existingWorkout->workout_images) > 0)
                    || $request->has('keep_workout_images');

                if ($caption === '' && $notes === '' && !$hasImages) {
                    $validator->errors()->add('caption', 'Add a caption, image, or both for your post.');
                }

                return;
            }

            if (! Schema::hasColumn('workout_logs', 'admin_event_id')) {
                if ($request->exists('admin_event_id') && trim((string) $request->input('admin_event_id')) !== '') {
                    $validator->errors()->add('admin_event_id', 'Linking workouts to challenges is unavailable until the database is migrated.');
                }

                return;
            }

            if (! $request->exists('admin_event_id')) {
                return;
            }

            $candidate = trim((string) $request->input('admin_event_id'));
            if ($candidate === '') {
                return;
            }

            $reg = ChallengeEnrollmentProgressService::findConfirmedRegistration((string) $viewer->id, $candidate);
            if (! $reg) {
                $validator->errors()->add('admin_event_id', 'You can only attach confirmed challenge enrolments.');

                return;
            }

            // Keeping an existing link on edit — don't block updates after goal is complete.
            if (
                $isUpdate
                && $existingWorkout
                && (string) ($existingWorkout->admin_event_id ?? '') === $candidate
            ) {
                return;
            }

            if (ChallengeEnrollmentProgressService::challengeDistanceGoalIsComplete((string) $viewer->id, $candidate)) {
                $validator->errors()->add(
                    'admin_event_id',
                    'This challenge distance is already complete. Log without linking it to this event, or choose another challenge.',
                );
            }
        });

        return $validator;
    }

    /**
     * Log a workout
     */
    public function log(Request $request)
    {
        $validator = $this->validateWorkout($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        
        $entryType = (string) $request->input('entry_type', 'workout');
        $isPostEntry = $entryType === 'post';

        // Calculate pace if distance and time provided
        $pace = null;
        if (!$isPostEntry && $request->filled('distance_km') && $request->filled('duration_seconds')) {
            $distance = (float) $request->input('distance_km');
            $seconds = (int) $request->input('duration_seconds');
            if ($distance > 0 && $seconds > 0) {
                $pace = ($seconds / 60) / $distance; // minutes per km
            }
        }

        $imageUrls = [];
        if ($request->hasFile('workout_images')) {
            foreach ($request->file('workout_images') as $image) {
                $storedPath = $image->store('workout-photos', 'public');
                $imageUrls[] = Storage::url($storedPath);
            }
        }

        $adminEventId = null;
        if (Schema::hasColumn('workout_logs', 'admin_event_id')) {
            if ($request->exists('admin_event_id')) {
                $adminEventId = trim((string) $request->input('admin_event_id')) ?: null;
            }
            if ($isPostEntry) {
                $adminEventId = null;
            }
        }

        $workout = WorkoutLog::create([
            'client_id' => $client->id,
            'admin_event_id' => $adminEventId,
            'entry_type' => $entryType,
            'workout_date' => $request->input('workout_date', now()->toDateString()),
            'workout_type' => $isPostEntry ? 'Shared Post' : $request->input('workout_type'),
            'duration_minutes' => $isPostEntry ? null : $request->input('duration_minutes'),
            'distance_km' => $isPostEntry ? null : $request->input('distance_km'),
            'duration_seconds' => $isPostEntry ? null : $request->input('duration_seconds'),
            'pace_min_per_km' => $pace,
            'status' => $request->input('status', 'completed'),
            'caption' => $request->input('caption'),
            'location' => $request->input('location'),
            'notes' => $request->input('notes'),
            'workout_images' => $imageUrls,
            'plan_day' => $request->input('plan_day'),
        ]);

        $fresh = $workout->fresh();
        ChallengeEnrollmentProgressService::onWorkoutCreated($fresh);
        $fresh->loadMissing('linkedAdminEvent:id,title');
        $fresh->loadCount(['likes', 'comments']);

        return response()->json([
            'success' => true,
            'message' => 'Workout logged successfully',
            'data' => [
                'workout' => $this->serializeWorkout($fresh, $client->id),
            ],
        ], 201);
    }

    /**
     * Update an existing workout log owned by user
     */
    public function update(Request $request, string $id)
    {
        $client = $request->user();
        $workout = WorkoutLog::where('id', $id)
            ->where('client_id', $client->id)
            ->first();

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        $validator = $this->validateWorkout($request, true, $workout);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var WorkoutLog $priorSnapshot Row state before this update (challenge mileage deltas). */
        $priorSnapshot = WorkoutLog::query()->find($workout->id);

        $entryType = (string) $request->input('entry_type', $workout->entry_type ?? 'workout');
        $isPostEntry = $entryType === 'post';

        $distance = $request->has('distance_km') ? (float) $request->input('distance_km') : (float) ($workout->distance_km ?? 0);
        $seconds = $request->has('duration_seconds') ? (int) $request->input('duration_seconds') : (int) ($workout->duration_seconds ?? 0);
        $pace = null;
        if (!$isPostEntry && $distance > 0 && $seconds > 0) {
            $pace = ($seconds / 60) / $distance;
        }

        $existingImages = is_array($workout->workout_images) ? $workout->workout_images : [];
        $replaceImages = $request->boolean('replace_images')
            || $request->has('keep_workout_images')
            || $request->hasFile('workout_images');

        $finalImages = $existingImages;
        if ($replaceImages) {
            $keepImages = $this->parseImageListInput($request, 'keep_workout_images');
            // Client sends resolved media URLs; DB stores /storage/... — compare canonical workout-photos paths.
            $keepIdentitySet = collect($keepImages)
                ->map(fn ($url) => WorkoutJsonPresenter::extractWorkoutPhotoRelativePath($url))
                ->filter()
                ->map(fn ($path) => strtolower($path))
                ->flip();
            $finalImages = collect($existingImages)
                ->filter(function ($url) use ($keepIdentitySet) {
                    $id = WorkoutJsonPresenter::extractWorkoutPhotoRelativePath($url);

                    return $id && $keepIdentitySet->has(strtolower($id));
                })
                ->values()
                ->all();

            $removedImages = array_diff($existingImages, $finalImages);
            foreach ($removedImages as $removedImageUrl) {
                $this->deleteWorkoutPhotoByUrl($removedImageUrl);
            }
        }

        if ($request->hasFile('workout_images')) {
            foreach ($request->file('workout_images') as $image) {
                $storedPath = $image->store('workout-photos', 'public');
                $finalImages[] = Storage::url($storedPath);
            }
        }

        $fallbackWorkoutDate = $workout->workout_date;
        if ($fallbackWorkoutDate instanceof \DateTimeInterface) {
            $fallbackWorkoutDate = $fallbackWorkoutDate->format('Y-m-d');
        } elseif (!is_string($fallbackWorkoutDate)) {
            $fallbackWorkoutDate = null;
        }

        $nextAdminEventId = $workout->admin_event_id;
        if (Schema::hasColumn('workout_logs', 'admin_event_id')) {
            if ($request->exists('admin_event_id')) {
                $nextAdminEventId = trim((string) $request->input('admin_event_id')) ?: null;
            }
            if ($isPostEntry) {
                $nextAdminEventId = null;
            }
        }

        $updatePayload = [
            'entry_type' => $entryType,
            'workout_date' => $request->input('workout_date', $fallbackWorkoutDate),
            'workout_type' => $request->input('workout_type', $isPostEntry ? 'Shared Post' : $workout->workout_type),
            'duration_minutes' => $isPostEntry ? null : $request->input('duration_minutes', $workout->duration_minutes),
            'distance_km' => $isPostEntry ? null : $request->input('distance_km', $workout->distance_km),
            'duration_seconds' => $isPostEntry ? null : $request->input('duration_seconds', $workout->duration_seconds),
            'pace_min_per_km' => $pace,
            'status' => $request->input('status', $workout->status),
            'caption' => $request->input('caption', $workout->caption),
            'location' => $request->input('location', $workout->location),
            'notes' => $request->input('notes', $workout->notes),
            'workout_images' => $finalImages,
            'plan_day' => $request->input('plan_day', $workout->plan_day),
        ];

        if (Schema::hasColumn('workout_logs', 'admin_event_id')) {
            $updatePayload['admin_event_id'] = $nextAdminEventId;
        }

        if (Schema::hasColumn('workout_logs', 'challenge_progress_approved_km')) {
            $priorEv = $priorSnapshot->admin_event_id ? (string) $priorSnapshot->admin_event_id : '';
            $nextEv = $nextAdminEventId ? (string) $nextAdminEventId : '';
            if ($priorEv !== $nextEv) {
                $updatePayload['challenge_progress_approved_km'] = null;
            }
        }

        $workout->update($updatePayload);

        $currentSnapshot = $workout->fresh();
        if ($priorSnapshot instanceof WorkoutLog && $currentSnapshot !== null && Schema::hasColumn('workout_logs', 'admin_event_id')) {
            ChallengeEnrollmentProgressService::syncEnrollmentForWorkoutChange($client->id, $priorSnapshot, $currentSnapshot);
        }

        $currentSnapshot?->loadMissing('linkedAdminEvent:id,title');
        $currentSnapshot?->loadCount(['likes', 'comments']);

        return response()->json([
            'success' => true,
            'message' => 'Workout updated successfully',
            'data' => [
                'workout' => $this->serializeWorkout($currentSnapshot, $client->id),
            ],
        ], 200);
    }

    /**
     * Delete a workout log owned by user
     */
    public function destroy(Request $request, string $id)
    {
        $client = $request->user();
        $workout = WorkoutLog::where('id', $id)
            ->where('client_id', $client->id)
            ->first();

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        if (is_array($workout->workout_images)) {
            foreach ($workout->workout_images as $imageUrl) {
                $this->deleteWorkoutPhotoByUrl($imageUrl);
            }
        }

        if (Schema::hasColumn($workout->getTable(), 'admin_event_id') && ! empty($workout->admin_event_id)) {
            EventProgressSubmissionService::beforeWorkoutDeleted($workout);
        }

        $workout->delete();

        return response()->json([
            'success' => true,
            'message' => 'Workout deleted successfully',
        ], 200);
    }

    /**
     * Get workout history
     */
    public function index(Request $request)
    {
        $client = $request->user();
        
        $query = WorkoutLog::where('client_id', $client->id)
            ->when(
                Schema::hasColumn((new WorkoutLog)->getTable(), 'admin_event_id'),
                fn ($q) => $q->with(['linkedAdminEvent:id,title']),
            )
            ->withCount(['likes', 'comments'])
            ->orderBy('workout_date', 'desc')
            ->orderBy('created_at', 'desc');

        // Optional filters
        if ($request->has('limit')) {
            $query->limit((int) $request->input('limit', 10));
        }

        if ($request->has('date_from')) {
            $query->where('workout_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('workout_date', '<=', $request->input('date_to'));
        }

        $workouts = $query->get()->map(
            fn (WorkoutLog $workout) => $this->serializeWorkout($workout, $client->id)
        )->values();

        return response()->json([
            'success' => true,
            'data' => [
                'workouts' => $workouts,
                'total' => $workouts->count(),
            ],
        ], 200);
    }

    /**
     * Social feed: all community workouts by default (startup mode), or following-only.
     */
    public function feed(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
            'sort' => 'nullable|in:ranked,chronological',
            'scope' => 'nullable|in:all,following',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $limit = min((int) $request->input('limit', 25), 100);
        $sort = (string) $request->input('sort', 'chronological');
        $scope = (string) $request->input('scope', 'all');
        $rankingEnabled = (bool) config('social.feed_ranking.enabled', true);
        $monitoringEnabled = (bool) config('social.feed_ranking.monitoring_enabled', true);
        $hasAdminEventId = Schema::hasColumn((new WorkoutLog)->getTable(), 'admin_event_id');

        $followingIds = $scope === 'following'
            ? $viewer->following()->pluck('clients.id')
            : collect();
        $followingLookup = array_fill_keys($followingIds->map(fn ($id) => (string) $id)->all(), true);

        $baseQuery = WorkoutLog::query()
            ->where('workout_logs.status', 'completed')
            ->join('clients', 'clients.id', '=', 'workout_logs.client_id')
            ->whereNull('clients.deleted_at')
            ->select('workout_logs.*')
            ->with(['client.profile'])
            ->when(
                $hasAdminEventId,
                fn ($q) => $q->with(['linkedAdminEvent:id,title']),
            )
            ->withCount(['likes', 'comments']);

        if ($scope === 'following') {
            $candidateClientIds = $followingIds->push($viewer->id)->unique()->values();
            $baseQuery->whereIn('workout_logs.client_id', $candidateClientIds);
        }

        if ($sort === 'chronological' || !$rankingEnabled) {
            $workoutRows = $baseQuery
                ->orderByDesc('workout_logs.workout_date')
                ->orderByDesc('workout_logs.created_at')
                ->limit($limit)
                ->get();
            $workouts = collect($this->serializeWorkouts($workoutRows, $viewer->id))->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'workouts' => $workouts,
                    'total' => $workouts->count(),
                    'sort' => 'chronological',
                    'scope' => $scope,
                    'experiment' => [
                        'ranking_enabled' => $rankingEnabled,
                        'requested_sort' => $sort,
                        'applied_sort' => 'chronological',
                        'scope' => $scope,
                    ],
                ],
            ], 200);
        }

        $workouts = $baseQuery
            ->orderByDesc('workout_logs.workout_date')
            ->orderByDesc('workout_logs.created_at')
            ->limit(200)
            ->get();

        $ranked = $this->feedRankingService
            ->rank($viewer, $workouts, $followingLookup)
            ->take($limit)
            ->values();

        $rankedWorkouts = $ranked->pluck('workout');
        $serializedById = collect($this->serializeWorkouts($rankedWorkouts, $viewer->id))
            ->keyBy('id');
        $serialized = $ranked->map(function (array $row) use ($serializedById, $viewer) {
            $payload = $serializedById->get($row['workout']->id)
                ?? $this->serializeWorkout($row['workout'], $viewer->id);
            $payload['ranking_score'] = $row['score'];

            return $payload;
        })->values();

        if ($monitoringEnabled) {
            Log::info('workout_feed_ranked_served', [
                'viewer_id' => $viewer->id,
                'requested_limit' => $limit,
                'scope' => $scope,
                'candidate_count' => $workouts->count(),
                'returned_count' => $serialized->count(),
                'top_score' => $serialized->first()['ranking_score'] ?? null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'workouts' => $serialized,
                'total' => $serialized->count(),
                'sort' => 'ranked',
                'scope' => $scope,
                'experiment' => [
                    'ranking_enabled' => $rankingEnabled,
                    'requested_sort' => $sort,
                    'applied_sort' => 'ranked',
                    'scope' => $scope,
                ],
            ],
        ], 200);
    }

    /**
     * Search public completed workout posts by keyword.
     */
    public function search(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = trim((string) $request->input('query'));
        $limit = (int) $request->input('limit', 20);

        $workoutRows = WorkoutLog::query()
            ->where('status', 'completed')
            ->when(
                Schema::hasColumn((new WorkoutLog)->getTable(), 'admin_event_id'),
                fn ($q2) => $q2->with(['linkedAdminEvent:id,title']),
            )
            ->where(function ($q) use ($query) {
                $q->where('workout_type', 'like', "%{$query}%")
                    ->orWhere('notes', 'like', "%{$query}%")
                    ->orWhere('caption', 'like', "%{$query}%")
                    ->orWhere('location', 'like', "%{$query}%")
                    ->orWhereHas('client', function ($clientQuery) use ($query) {
                        $clientQuery->where('email', 'like', "%{$query}%")
                            ->orWhereHas('profile', function ($profileQuery) use ($query) {
                                $profileQuery->where('display_name', 'like', "%{$query}%")
                                    ->orWhere('first_name', 'like', "%{$query}%")
                                    ->orWhere('last_name', 'like', "%{$query}%");
                            });
                    });
            })
            ->with(['client.profile'])
            ->withCount(['likes', 'comments'])
            ->orderByDesc('workout_date')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
        $workouts = collect($this->serializeWorkouts($workoutRows, $viewer->id))->values();

        return response()->json([
            'success' => true,
            'data' => [
                'query' => $query,
                'workouts' => $workouts,
                'total' => $workouts->count(),
            ],
        ], 200);
    }

    /**
     * Get today's workout status
     */
    public function today(Request $request)
    {
        $client = $request->user();
        $today = now()->toDateString();

        $workout = WorkoutLog::where('client_id', $client->id)
            ->where('workout_date', $today)
            ->where('status', 'completed')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'has_workout' => $workout !== null,
                'workout' => $workout,
            ],
        ], 200);
    }

    /**
     * Get workout statistics
     */
    public function stats(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->workoutStatsService->buildPayloadForClient((string) $client->id),
        ], 200);
    }

    public function like(Request $request, string $workoutId)
    {
        $client = $request->user();
        $workout = WorkoutLog::find($workoutId);

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        $like = WorkoutLike::firstOrCreate([
            'workout_log_id' => $workout->id,
            'client_id' => $client->id,
        ]);

        if ($like->wasRecentlyCreated) {
            ClientNotificationService::workoutLiked($client, $workout);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => WorkoutLike::where('workout_log_id', $workout->id)->count(),
                'is_liked_by_me' => true,
            ],
        ], 200);
    }

    public function unlike(Request $request, string $workoutId)
    {
        $client = $request->user();
        $workout = WorkoutLog::find($workoutId);

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        WorkoutLike::where('workout_log_id', $workout->id)
            ->where('client_id', $client->id)
            ->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => WorkoutLike::where('workout_log_id', $workout->id)->count(),
                'is_liked_by_me' => false,
            ],
        ], 200);
    }

    public function likes(Request $request, string $workoutId)
    {
        $viewer = $request->user();
        $workout = WorkoutLog::find($workoutId);

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        $likes = WorkoutLike::query()
            ->where('workout_log_id', $workout->id)
            ->with('client.profile')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkoutLike $like) => $this->mapClientSummary($like->client, $viewer))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'likes' => $likes,
                'likes_count' => $likes->count(),
            ],
        ], 200);
    }

    public function comments(Request $request, string $workoutId)
    {
        $viewer = $request->user();
        $workout = WorkoutLog::find($workoutId);

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        $comments = WorkoutComment::query()
            ->where('workout_log_id', $workout->id)
            ->whereNull('parent_comment_id')
            ->withCount('likes')
            ->with([
                'client.profile',
                'replies' => fn ($query) => $query->withCount('likes')->with('client.profile'),
            ])
            ->orderBy('created_at')
            ->get()
            ->map(fn (WorkoutComment $comment) => $this->serializeComment($comment, $viewer->id))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'comments' => $comments,
                'comments_count' => WorkoutComment::where('workout_log_id', $workout->id)->count(),
            ],
        ], 200);
    }

    public function addComment(Request $request, string $workoutId)
    {
        $viewer = $request->user();
        $workout = WorkoutLog::find($workoutId);

        if (!$workout) {
            return response()->json([
                'success' => false,
                'message' => 'Workout not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|max:1000',
            'parent_comment_id' => 'nullable|uuid|exists:workout_comments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $parentCommentId = $request->input('parent_comment_id');
        if ($parentCommentId) {
            $parent = WorkoutComment::where('id', $parentCommentId)
                ->where('workout_log_id', $workout->id)
                ->first();

            if (!$parent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent comment not found for this post.',
                ], 422);
            }
        }

        $comment = WorkoutComment::create([
            'workout_log_id' => $workout->id,
            'client_id' => $viewer->id,
            'parent_comment_id' => $parentCommentId,
            'body' => trim((string) $request->input('body')),
        ])->load('client.profile');

        ClientNotificationService::workoutCommented($viewer, $workout, $comment);

        $comment->likes_count = 0;

        return response()->json([
            'success' => true,
            'data' => [
                'comment' => $this->serializeComment($comment, $viewer->id, false),
                'comments_count' => WorkoutComment::where('workout_log_id', $workout->id)->count(),
            ],
        ], 201);
    }

    public function likeComment(Request $request, string $commentId)
    {
        $viewer = $request->user();
        $comment = WorkoutComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found.',
            ], 404);
        }

        $like = WorkoutCommentLike::firstOrCreate([
            'workout_comment_id' => $comment->id,
            'client_id' => $viewer->id,
        ]);

        if ($like->wasRecentlyCreated) {
            ClientNotificationService::commentLiked($viewer, $comment);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => WorkoutCommentLike::where('workout_comment_id', $comment->id)->count(),
                'is_liked_by_me' => true,
            ],
        ], 200);
    }

    public function unlikeComment(Request $request, string $commentId)
    {
        $viewer = $request->user();
        $comment = WorkoutComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found.',
            ], 404);
        }

        WorkoutCommentLike::where('workout_comment_id', $comment->id)
            ->where('client_id', $viewer->id)
            ->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'likes_count' => WorkoutCommentLike::where('workout_comment_id', $comment->id)->count(),
                'is_liked_by_me' => false,
            ],
        ], 200);
    }

    public function commentLikes(Request $request, string $commentId)
    {
        $viewer = $request->user();
        $comment = WorkoutComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found.',
            ], 404);
        }

        $likes = WorkoutCommentLike::query()
            ->where('workout_comment_id', $comment->id)
            ->with('client.profile')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkoutCommentLike $like) => $this->mapClientSummary($like->client, $viewer))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'likes' => $likes,
                'likes_count' => $likes->count(),
            ],
        ], 200);
    }
}
