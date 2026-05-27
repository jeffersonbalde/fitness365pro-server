<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\ClientProfile;
use App\Services\EventEnrollmentProgressService;
use App\Support\ViewerChallengeProgressPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class RaceResultsController extends Controller
{
    public function completedEvents(Request $request)
    {
        if (! Schema::hasTable('admin_events')) {
            return response()->json([
                'success' => true,
                'data' => ['events' => [], 'total' => 0],
            ]);
        }

        $now = now('UTC');
        $registrationsReady = Schema::hasTable('client_admin_event_registrations');
        $registrationStatusTracked = $registrationsReady
            && Schema::hasColumn('client_admin_event_registrations', 'registration_status');

        $query = AdminEvent::query();
        if ($registrationsReady) {
            if ($registrationStatusTracked) {
                $query->withCount([
                    'registrations as participants_count' => function ($q) {
                        $q->where('registration_status', 'confirmed');
                    },
                ]);
            } else {
                $query->withCount('registrations as participants_count');
            }
        }

        $items = $query
            ->completed($now)
            ->orderByDesc('ends_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $events = $items->map(function (AdminEvent $event) use ($registrationsReady) {
            return [
                'id' => (string) $event->id,
                'title' => (string) $event->title,
                'category' => (string) ($event->category ?? ''),
                'location' => (string) ($event->location ?? ''),
                'image_url' => $event->image_url,
                'starts_at' => $event->starts_at?->toISOString(),
                'ends_at' => $event->ends_at?->toISOString(),
                'participants_count' => $registrationsReady
                    ? (int) ($event->participants_count ?? 0)
                    : 0,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'events' => $events,
                'total' => $events->count(),
            ],
        ]);
    }

    public function eventResults(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:120',
            'category' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $now = now('UTC');
        $event = AdminEvent::query()
            ->where('id', $id)
            ->completed($now)
            ->first();

        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Completed event not found.'], 404);
        }

        if (! Schema::hasTable('client_admin_event_registrations')) {
            return response()->json([
                'success' => true,
                'data' => $this->emptyResultsPayload($event),
            ]);
        }

        $search = trim((string) $request->input('search', ''));
        $categoryFilter = trim((string) $request->input('category', ''));
        $limit = (int) $request->input('limit', 100);
        $viewer = $request->user();
        $registrationStatusTracked = Schema::hasColumn('client_admin_event_registrations', 'registration_status');
        $progressReady = Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');
        $runningSelectionsReady = Schema::hasTable('client_admin_event_running_selections');

        $baseQuery = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        $listQuery = (clone $baseQuery)->with(['client.profile']);

        if ($progressReady) {
            $listQuery
                ->orderByRaw('COALESCE(progress_logged_km, 0) DESC')
                ->orderByRaw('CASE WHEN progress_pace_min_per_km IS NULL OR progress_pace_min_per_km <= 0 THEN 999999 ELSE progress_pace_min_per_km END ASC')
                ->orderBy('updated_at');
        } else {
            $listQuery->orderByDesc('created_at');
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, ClientAdminEventRegistration> $allRegs */
        $allRegs = $listQuery->get();

        $selectionsByClient = collect();
        if ($runningSelectionsReady && $allRegs->isNotEmpty()) {
            $selectionsByClient = ClientAdminEventRunningSelection::query()
                ->where('admin_event_id', $event->id)
                ->whereIn('client_id', $allRegs->pluck('client_id'))
                ->get()
                ->keyBy(static fn ($row) => (string) $row->client_id);
        }

        $categoryBuckets = [];

        $entries = $allRegs->values()->map(function (ClientAdminEventRegistration $reg, int $index) use (
            $event,
            $viewer,
            $progressReady,
            $selectionsByClient,
            &$categoryBuckets,
        ) {
            $client = $reg->client;
            if ($client === null) {
                return null;
            }

            $progress = $progressReady
                ? $this->progressMetricsForRegistration($event, $reg)
                : [
                    'logged_distance_km' => 0.0,
                    'goal_distance_km' => null,
                    'progress_percent' => null,
                    'target_label' => null,
                    'pace_min_per_km' => null,
                    'goal_completed' => false,
                ];

            /** @var ClientAdminEventRunningSelection|null $selection */
            $selection = $selectionsByClient->get((string) $client->id);
            $distanceKey = $selection ? (string) ($selection->distance_key ?? '') : '';
            $distanceLabel = $selection
                ? (EventEnrollmentProgressService::runningTargetDisplay(
                    (string) $selection->distance_key,
                    $selection->distance_label !== null ? (string) $selection->distance_label : null
                ) ?? (string) ($reg->progress_target_label ?? ''))
                : (string) ($reg->progress_target_label ?? '');

            if ($distanceLabel === '' && strtolower((string) $event->category) !== '') {
                $distanceLabel = ucfirst((string) $event->category);
            }

            $categoryKey = $distanceKey !== '' ? $distanceKey : '_general';
            if (! isset($categoryBuckets[$categoryKey])) {
                $categoryBuckets[$categoryKey] = [
                    'key' => $categoryKey,
                    'label' => $distanceLabel !== '' ? $distanceLabel : 'General',
                ];
            }

            $goalKm = $progress['goal_distance_km'] ?? null;
            $pace = $progress['pace_min_per_km'] ?? null;
            $finishTimeMinutes = $this->estimatedFinishTimeMinutes($pace, $goalKm);

            return [
                'global_rank' => $index + 1,
                'category_key' => $categoryKey,
                'category_label' => $distanceLabel !== '' ? $distanceLabel : 'General',
                'progress' => $progress,
                'finish_time_minutes' => $finishTimeMinutes,
                'user' => $this->mapClientSummary($client, $viewer),
                'registered_at' => $reg->created_at?->toISOString(),
            ];
        })->filter()->values();

        $categories = collect($categoryBuckets)
            ->values()
            ->sortBy('label')
            ->values()
            ->all();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $entries = $entries->filter(function (array $entry) use ($needle) {
                $user = $entry['user'] ?? [];
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($user['display_name'] ?? ''),
                    (string) ($user['email'] ?? ''),
                ])));

                return $haystack !== '' && str_contains($haystack, $needle);
            })->values();
        }

        if ($categoryFilter !== '' && $categoryFilter !== 'all') {
            $entries = $entries->filter(
                fn (array $entry) => (string) ($entry['category_key'] ?? '') === $categoryFilter
            )->values();
        }

        $entries = $entries->map(function (array $entry, int $index) {
            $entry['rank'] = $index + 1;

            return $entry;
        })->values();

        $viewerEntry = $entries->firstWhere('user.id', (string) $viewer->id);
        $limitedEntries = $entries->take($limit)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => (string) $event->id,
                    'title' => (string) $event->title,
                    'category' => (string) ($event->category ?? ''),
                    'location' => (string) ($event->location ?? ''),
                    'image_url' => $event->image_url,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'participants_count' => (clone $baseQuery)->count(),
                ],
                'categories' => $categories,
                'results' => $limitedEntries,
                'viewer_result' => $viewerEntry,
                'total' => $entries->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResultsPayload(AdminEvent $event): array
    {
        return [
            'event' => [
                'id' => (string) $event->id,
                'title' => (string) $event->title,
                'category' => (string) ($event->category ?? ''),
                'location' => (string) ($event->location ?? ''),
                'image_url' => $event->image_url,
                'starts_at' => $event->starts_at?->toISOString(),
                'ends_at' => $event->ends_at?->toISOString(),
                'participants_count' => 0,
            ],
            'categories' => [],
            'results' => [],
            'viewer_result' => null,
            'total' => 0,
        ];
    }

    private function displayNameFromProfile(?ClientProfile $pf): string
    {
        if ($pf !== null) {
            $disp = trim((string) ($pf->display_name ?? ''));
            if ($disp !== '') {
                return $disp;
            }
            $composed = trim(trim((string) ($pf->first_name ?? '')).' '.trim((string) ($pf->last_name ?? '')));
            if ($composed !== '') {
                return $composed;
            }
        }

        return 'Fitness 365 member';
    }

    /**
     * @return array<string, mixed>
     */
    private function mapClientSummary(Client $client, ?Client $viewer = null): array
    {
        $profile = $client->profile;
        $displayName = $this->displayNameFromProfile($profile);
        if ($displayName === 'Fitness 365 member' && $client->email) {
            $displayName = explode('@', (string) $client->email)[0] ?? 'User';
        }

        $mapped = [
            'id' => (string) $client->id,
            'email' => $client->email,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
            'city' => $profile?->city,
            'province' => $profile?->province,
        ];

        if ($viewer && $viewer->id !== $client->id && Schema::hasTable('client_follows')) {
            $mapped['is_following'] = $viewer->following()
                ->where('clients.id', $client->id)
                ->exists();
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function progressMetricsForRegistration(AdminEvent $event, ClientAdminEventRegistration $reg): array
    {
        $logged = round((float) ($reg->progress_logged_km ?? 0), 2);
        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;

        if (($goal === null || $goal <= 0.0)
            && Schema::hasColumn('admin_events', 'mileage_challenge_km')
            && $event->mileage_challenge_km !== null
            && (float) $event->mileage_challenge_km > 0.0) {
            $goal = (float) $event->mileage_challenge_km;
        }

        $percent = null;
        if ($goal !== null && $goal > 0.0) {
            $pctRounded = min(100.0, round(($logged / $goal) * 100, 1));
            if ($logged + 1e-6 >= max(0.0, $goal - 0.08)) {
                $percent = 100.0;
            } else {
                $percent = $pctRounded;
            }
        }

        $pace = $reg->progress_pace_min_per_km !== null && (float) $reg->progress_pace_min_per_km > 0
            ? round((float) $reg->progress_pace_min_per_km, 2)
            : null;

        return [
            'logged_distance_km' => $logged,
            'goal_distance_km' => $goal !== null ? round($goal, 2) : null,
            'progress_percent' => $percent,
            'target_label' => $reg->progress_target_label ? (string) $reg->progress_target_label : null,
            'pace_min_per_km' => $pace,
            'goal_completed' => ViewerChallengeProgressPresenter::distanceGoalIsSatisfied([
                'logged_distance_km' => $logged,
                'goal_distance_km' => $goal,
                'progress_percent' => $percent,
                'mileage_challenge_km' => Schema::hasColumn('admin_events', 'mileage_challenge_km')
                    ? ($event->mileage_challenge_km !== null ? (float) $event->mileage_challenge_km : null)
                    : null,
            ]),
        ];
    }

    private function estimatedFinishTimeMinutes(?float $paceMinPerKm, ?float $distanceKm): ?float
    {
        if ($paceMinPerKm === null || $paceMinPerKm <= 0 || $distanceKm === null || $distanceKm <= 0) {
            return null;
        }

        return round($paceMinPerKm * $distanceKm, 2);
    }
}
