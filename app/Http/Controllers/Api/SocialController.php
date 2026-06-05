<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientBadge;
use App\Models\ClientFollow;
use App\Models\WorkoutLog;
use App\Models\WorkoutLike;
use App\Services\ClientNotificationService;
use App\Services\Social\BuddyScoringService;
use App\Services\WorkoutStatsService;
use App\Support\SchemaCapabilities;
use App\Support\WorkoutJsonPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SocialController extends Controller
{
    public function __construct(
        private readonly BuddyScoringService $buddyScoringService,
        private readonly WorkoutStatsService $workoutStatsService,
    ) {
    }

    private function serializeWorkout(WorkoutLog $workout, string $viewerId): array
    {
        return WorkoutJsonPresenter::serializeForClientViewer($workout, $viewerId);
    }

    private function mapClientSummary(Client $client, ?Client $viewer = null, ?bool $isFollowing = null): array
    {
        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        if (!$displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'User';
        }

        $mapped = [
            'id' => $client->id,
            'email' => $client->email,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
            'city' => $profile?->city,
            'province' => $profile?->province,
            'primary_niche' => $profile?->primary_niche,
            'secondary_niches' => $profile?->secondary_niches,
        ];

        if ($viewer && $viewer->id !== $client->id) {
            $mapped['is_following'] = $isFollowing ?? $viewer->following()
                ->where('clients.id', $client->id)
                ->exists();
        }

        return $mapped;
    }

    private function getPublicProfilePayload(Client $target, Client $viewer, bool $includeWorkoutStats = false): array
    {
        $profile = $target->profile;
        $goals = $target->goals()->select('id', 'name', 'slug')->orderBy('name')->get();
        $badges = [];
        if (Schema::hasTable('client_badges')) {
            $badges = ClientBadge::query()
                ->where('client_id', $target->id)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'label', 'image_url', 'created_at'])
                ->map(fn (ClientBadge $badge) => [
                    'id' => $badge->id,
                    'label' => $badge->label,
                    'image_url' => $badge->image_url,
                    'created_at' => $badge->created_at?->toISOString(),
                ])
                ->values()
                ->all();
        }
        $workoutRows = WorkoutLog::query()
            ->where('client_id', $target->id)
            ->where('status', 'completed')
            ->when(
                SchemaCapabilities::hasWorkoutAdminEventId(),
                fn ($q) => $q->with(['linkedAdminEvent:id,title']),
            )
            ->withCount(['likes', 'comments'])
            ->orderByDesc('workout_date')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $workouts = WorkoutJsonPresenter::serializeManyForClientViewer($workoutRows, (string) $viewer->id);

        $isFollowing = $viewer->id !== $target->id
            && DB::table('client_follows')
                ->where('follower_client_id', $viewer->id)
                ->where('followed_client_id', $target->id)
                ->exists();

        $followingCount = (int) ($target->following_count ?? $target->following()->count());
        $followersCount = (int) ($target->followers_count ?? $target->followers()->count());

        $payload = [
            'user' => $this->mapClientSummary($target, $viewer, $isFollowing),
            'profile' => [
                'display_name' => $profile?->display_name,
                'first_name' => $profile?->first_name,
                'last_name' => $profile?->last_name,
                'bio' => $profile?->bio,
                'profile_picture_url' => $profile?->profile_picture_url,
                'cover_photo_url' => $profile?->cover_photo_url,
                'city' => $profile?->city,
                'province' => $profile?->province,
                'country' => $profile?->country,
                'gender' => $profile?->gender,
                'date_of_birth' => $profile?->date_of_birth,
                'height_cm' => $profile?->height_cm,
                'current_weight_kg' => $profile?->current_weight_kg,
                'target_weight_kg' => $profile?->target_weight_kg,
                'experience_level' => $profile?->experience_level,
                'experience_running' => $profile?->experience_running,
                'experience_gym' => $profile?->experience_gym,
                'experience_others_title' => $profile?->experience_others_title,
                'experience_others' => $profile?->experience_others,
                'primary_niche' => $profile?->primary_niche,
                'secondary_niches' => $profile?->secondary_niches,
                'workout_preferences' => $profile?->workout_preferences,
                'nutrition_preferences' => $profile?->nutrition_preferences,
                'badges' => $badges,
            ],
            'goals' => $goals,
            'social' => [
                'is_self' => $viewer->id === $target->id,
                'is_following' => $isFollowing,
                'following_count' => $followingCount,
                'followers_count' => $followersCount,
                'activities_count' => WorkoutLog::where('client_id', $target->id)
                    ->where('status', 'completed')
                    ->count(),
            ],
            'timeline' => $workouts,
        ];

        if ($includeWorkoutStats) {
            $payload['workout_stats'] = $this->workoutStatsService->buildPayloadForClient((string) $target->id);
        }

        return $payload;
    }

    public function stats(Request $request)
    {
        $client = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'following_count' => $client->following()->count(),
                'followers_count' => $client->followers()->count(),
                'activities_count' => WorkoutLog::where('client_id', $client->id)
                    ->where('status', 'completed')
                    ->count(),
            ],
        ], 200);
    }

    public function followers(Request $request)
    {
        $client = $request->user();
        $followers = $client->followers()->with('profile')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'followers' => $followers->map(fn (Client $c) => $this->mapClientSummary($c))->values(),
            ],
        ], 200);
    }

    public function userProfile(Request $request, string $clientId)
    {
        $viewer = $request->user();
        $target = Client::query()
            ->with(['profile'])
            ->withCount(['following', 'followers'])
            ->find($clientId);

        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $includeWorkoutStats = $request->boolean('include_workout_stats', false);
        $cacheKey = sprintf(
            'social:profile:%s:viewer:%s:stats:%d:v1',
            $clientId,
            $viewer->id,
            $includeWorkoutStats ? 1 : 0,
        );

        $payload = Cache::remember($cacheKey, 30, fn () => $this->getPublicProfilePayload(
            $target,
            $viewer,
            $includeWorkoutStats,
        ));

        return response()->json([
            'success' => true,
            'data' => $payload,
        ], 200);
    }

    public function userWorkoutStats(Request $request, string $clientId)
    {
        $target = Client::query()->find($clientId);

        if (! $target) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->workoutStatsService->buildPayloadForClient((string) $target->id),
        ], 200);
    }

    public function userFollowers(Request $request, string $clientId)
    {
        $viewer = $request->user();
        $target = Client::with('profile')->find($clientId);

        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $followers = $target->followers()->with('profile')->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->mapClientSummary($target, $viewer),
                'followers' => $followers->map(fn (Client $c) => $this->mapClientSummary($c, $viewer))->values(),
            ],
        ], 200);
    }

    public function userFollowing(Request $request, string $clientId)
    {
        $viewer = $request->user();
        $target = Client::with('profile')->find($clientId);

        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $following = $target->following()->with('profile')->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->mapClientSummary($target, $viewer),
                'following' => $following->map(fn (Client $c) => $this->mapClientSummary($c, $viewer))->values(),
            ],
        ], 200);
    }

    public function following(Request $request)
    {
        $client = $request->user();
        $following = $client->following()->with('profile')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'following' => $following->map(fn (Client $c) => $this->mapClientSummary($c))->values(),
            ],
        ], 200);
    }

    public function discover(Request $request)
    {
        $client = $request->user();
        $validator = Validator::make($request->all(), [
            'query' => 'nullable|string|max:120',
            'niche' => 'nullable|in:running,gym,biking,hybrid',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $query = Client::where('id', '!=', $client->id)->with('profile');

        if ($request->filled('query')) {
            $term = trim((string) $request->input('query'));
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', "%{$term}%")
                    ->orWhereHas('profile', function ($p) use ($term) {
                        $p->where('display_name', 'like', "%{$term}%")
                            ->orWhere('first_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%");
                    });
            });
        }

        if ($request->filled('niche')) {
            $niche = (string) $request->input('niche');
            $query->whereHas('profile', function ($p) use ($niche) {
                $p->where('primary_niche', $niche);
            });
        }

        $followingIds = $client->following()
            ->pluck('clients.id')
            ->map(fn ($id) => (string) $id)
            ->values();
        $paginator = $query
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $results = $paginator->getCollection()->map(function (Client $c) use ($followingIds) {
            $mapped = $this->mapClientSummary($c);
            $mapped['is_following'] = $followingIds->contains((string) $c->id);
            return $mapped;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ], 200);
    }

    public function suggestedBuddies(Request $request)
    {
        $client = $request->user();
        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $page = (int) ($request->input('page', 1));
        $perPage = (int) ($request->input('per_page', 20));

        $followingIds = $client->following()->pluck('clients.id');
        $candidateQuery = Client::query()
            ->where('id', '!=', $client->id)
            ->whereNotIn('id', $followingIds)
            ->with(['profile', 'goals:id,slug']);

        $candidates = $candidateQuery->limit(60)->get();
        $scored = $this->buddyScoringService->scoreCandidates($client, $candidates);

        $total = $scored->count();
        $offset = max(0, ($page - 1) * $perPage);
        $items = $scored
            ->slice($offset, $perPage)
            ->values()
            ->map(function (array $row) use ($candidates, $client) {
                $candidate = $candidates->firstWhere('id', $row['candidate_id']);
                if (!$candidate instanceof Client) {
                    return null;
                }

                $reasonTags = [];
                $signals = $row['signals'] ?? [];
                if (!empty($signals['same_primary_niche'])) {
                    $reasonTags[] = 'same_niche';
                }
                if (($signals['goal_overlap_count'] ?? 0) > 0) {
                    $reasonTags[] = 'shared_goals';
                }
                if (($row['breakdown']['location'] ?? 0) >= 7.5) {
                    $reasonTags[] = 'nearby';
                }
                if (($signals['completed_last_30_days'] ?? 0) >= 4) {
                    $reasonTags[] = 'active_recently';
                }

                if (empty($reasonTags)) {
                    $reasonTags[] = 'new_connection';
                }

                return [
                    ...$this->mapClientSummary($candidate, $client),
                    'score' => $row['score'],
                    'reason_tags' => $reasonTags,
                    'signals' => $signals,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $items,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                ],
            ],
        ], 200);
    }

    private function calculateCurrentStreak(string $clientId): int
    {
        $streak = 0;
        $date = now()->toDateString();

        while (true) {
            $workout = WorkoutLog::where('client_id', $clientId)
                ->where('workout_date', $date)
                ->where('status', 'completed')
                ->first();

            if (!$workout) {
                break;
            }

            $streak++;
            $date = date('Y-m-d', strtotime($date . ' -1 day'));
        }

        return $streak;
    }

    public function leaderboard(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:weekly,all_time',
            'limit' => 'nullable|integer|min:1|max:100',
            'niche' => 'nullable|in:all,gym,running,biking,hybrid',
            'city' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $period = (string) $request->input('period', 'weekly');
        $limit = (int) $request->input('limit', 50);
        $niche = (string) $request->input('niche', 'all');
        $city = trim((string) $request->input('city', ''));

        $baseQuery = WorkoutLog::query()->where('status', 'completed');
        if ($period === 'weekly') {
            $baseQuery->whereBetween('workout_date', [
                now()->startOfWeek()->toDateString(),
                now()->endOfWeek()->toDateString(),
            ]);
        }

        if ($niche !== 'all') {
            $baseQuery->whereHas('client.profile', function ($query) use ($niche) {
                $query->where('primary_niche', $niche);
            });
        }

        if ($city !== '') {
            $baseQuery->whereHas('client.profile', function ($query) use ($city) {
                $query->where('city', $city);
            });
        }

        $rows = $baseQuery
            ->select('client_id')
            ->selectRaw('COUNT(*) as workouts_count')
            ->selectRaw('COALESCE(SUM(distance_km), 0) as total_distance_km')
            ->selectRaw('COALESCE(SUM(duration_minutes), 0) as total_minutes')
            ->groupBy('client_id')
            ->orderByDesc('workouts_count')
            ->orderByDesc('total_distance_km')
            ->orderByDesc('total_minutes')
            ->limit($limit)
            ->get();

        $clientIds = $rows->pluck('client_id')->values();
        $clientsById = Client::query()
            ->with('profile')
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        $entries = $rows->values()->map(function ($row, $index) use ($clientsById, $viewer) {
            $client = $clientsById->get($row->client_id);
            if (!$client) {
                return null;
            }

            $workoutsCount = (int) ($row->workouts_count ?? 0);
            $distanceKm = round((float) ($row->total_distance_km ?? 0), 2);
            $minutes = (int) ($row->total_minutes ?? 0);
            $streak = $this->calculateCurrentStreak($client->id);
            $score = (int) round(($workoutsCount * 100) + ($distanceKm * 8) + ($minutes * 0.6) + ($streak * 25));

            return [
                'rank' => $index + 1,
                'score' => $score,
                'workouts_count' => $workoutsCount,
                'total_distance_km' => $distanceKm,
                'total_minutes' => $minutes,
                'current_streak' => $streak,
                'user' => $this->mapClientSummary($client, $viewer),
            ];
        })->filter()->values();

        $viewerRank = $entries->firstWhere('user.id', $viewer->id);

        $cityOptionsQuery = WorkoutLog::query()
            ->where('status', 'completed')
            ->join('client_profiles', 'client_profiles.client_id', '=', 'workout_logs.client_id');

        if ($period === 'weekly') {
            $cityOptionsQuery->whereBetween('workout_logs.workout_date', [
                now()->startOfWeek()->toDateString(),
                now()->endOfWeek()->toDateString(),
            ]);
        }

        if ($niche !== 'all') {
            $cityOptionsQuery->where('client_profiles.primary_niche', $niche);
        }

        $cityOptions = $cityOptionsQuery
            ->whereNotNull('client_profiles.city')
            ->where('client_profiles.city', '!=', '')
            ->distinct()
            ->orderBy('client_profiles.city')
            ->pluck('client_profiles.city')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'niche' => $niche,
                'city' => $city !== '' ? $city : null,
                'cities' => $cityOptions,
                'leaderboard' => $entries,
                'viewer_rank' => $viewerRank,
                'total' => $entries->count(),
            ],
        ], 200);
    }

    public function follow(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid|exists:clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $me = $request->user();
        $targetId = $request->input('client_id');

        if ($me->id === $targetId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot follow yourself.',
            ], 422);
        }

        $follow = ClientFollow::firstOrCreate([
            'follower_client_id' => $me->id,
            'followed_client_id' => $targetId,
        ]);

        if ($follow->wasRecentlyCreated) {
            $target = Client::query()->find($targetId);
            if ($target) {
                ClientNotificationService::newFollower($me, $target);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Followed successfully.',
        ], 200);
    }

    public function unfollow(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid|exists:clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $me = $request->user();
        $targetId = $request->input('client_id');

        ClientFollow::where('follower_client_id', $me->id)
            ->where('followed_client_id', $targetId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Unfollowed successfully.',
        ], 200);
    }
}

