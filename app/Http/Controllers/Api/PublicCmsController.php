<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAnnouncement;
use App\Models\AdminEvent;
use App\Models\AdminPost;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\ClientProfile;
use App\Services\EventEnrollmentProgressService;
use App\Support\ViewerChallengeProgressPresenter;
use App\Support\RegistrationDeliveryCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PublicCmsController extends Controller
{
    /**
     * @return array<string, mixed>|null
     */
    private function challengeProgressSliceForViewer(AdminEvent $event, ?ClientAdminEventRegistration $reg, string $clientId): ?array
    {
        return ViewerChallengeProgressPresenter::slice($event, $reg, $clientId);
    }

    /** @param  iterable<int, mixed>  $tokens */
    private function initialsFromNameTokens(iterable $tokens): string
    {
        $out = '';
        foreach ($tokens as $t) {
            $t = trim((string) $t);
            if ($t !== '') {
                $out .= strtoupper(mb_substr($t, 0, 1));
            }
            if (mb_strlen($out) >= 2) {
                break;
            }
        }

        return $out !== '' ? Str::limit($out, 2, '') : '?';
    }

    private function initialsFromRegistrationRow(?ClientProfile $pf): string
    {
        if ($pf !== null) {
            $disp = trim((string) ($pf->display_name ?? ''));
            if ($disp !== '') {
                return $this->initialsFromNameTokens(preg_split('/\s+/u', $disp) ?: []);
            }
            $fn = trim((string) ($pf->first_name ?? ''));
            $ln = trim((string) ($pf->last_name ?? ''));
            if ($fn !== '' || $ln !== '') {
                return $this->initialsFromNameTokens([$fn, $ln]);
            }
        }

        return '?';
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
    private function mapClientSummaryForLeaderboard(Client $client, ?Client $viewer = null): array
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
            'primary_niche' => $profile?->primary_niche,
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

    public function feed()
    {
        if (!Schema::hasTable('admin_posts')) {
            return response()->json([
                'success' => true,
                'data' => ['posts' => [], 'total' => 0],
            ]);
        }
        $now = now('UTC');

        $posts = AdminPost::query()
            ->with('admin:id,name,email')
            ->where('status', 'published')
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->orderByDesc('publish_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(function (AdminPost $post) {
                return [
                    'id' => $post->id,
                    'source' => 'admin_post',
                    'title' => $post->title,
                    'body' => $post->body,
                    'image_url' => $post->image_url,
                    'published_at' => ($post->publish_at ?? $post->created_at)?->toISOString(),
                    'author' => [
                        'name' => $post->admin?->name ?? 'Administrator',
                        'email' => $post->admin?->email,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'posts' => $posts,
                'total' => $posts->count(),
            ],
        ]);
    }

    public function announcements()
    {
        if (!Schema::hasTable('admin_announcements')) {
            return response()->json([
                'success' => true,
                'data' => ['announcements' => [], 'total' => 0],
            ]);
        }
        $now = now('UTC');
        $items = AdminAnnouncement::query()
            ->with('admin:id,name,email')
            ->where('status', 'published')
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->orderByDesc('priority')
            ->orderByDesc('publish_at')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'announcements' => $items,
                'total' => $items->count(),
            ],
        ]);
    }

    public function events(Request $request)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json([
                'success' => true,
                'data' => ['events' => [], 'total' => 0],
            ]);
        }

        $now = now('UTC');

        $registrationsReady = Schema::hasTable('client_admin_event_registrations');
        $registrationStatusTracked = $registrationsReady
            && Schema::hasColumn('client_admin_event_registrations', 'registration_status');

        $query = AdminEvent::query()->with('admin:id,name,email');
        if ($registrationsReady) {
            if ($registrationStatusTracked) {
                $query->withCount([
                    'registrations as participants_count' => function ($q) {
                        $q->where('registration_status', 'confirmed');
                    },
                ]);
            } else {
                // Legacy schema: count all registration rows until payment/status migration runs.
                $query->withCount('registrations as participants_count');
            }
        }
        $items = $query
            ->active($now)
            ->orderBy('starts_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $viewerId = $request->user()?->id;
        $registrationStatusTrackedForViewer = Schema::hasTable('client_admin_event_registrations')
            && Schema::hasColumn('client_admin_event_registrations', 'registration_status');

        /** @var \Illuminate\Support\Collection<string, ClientAdminEventRegistration> $viewerRegsByEvent */
        $viewerRegsByEvent = collect();

        if ($viewerId !== null && Schema::hasTable('client_admin_event_registrations') && $items->isNotEmpty()) {
            $viewerRegsByEvent = ClientAdminEventRegistration::query()
                ->where('client_id', (string) $viewerId)
                ->whereIn('admin_event_id', $items->pluck('id'))
                ->get()
                ->keyBy(static fn ($row) => (string) $row->admin_event_id);
        }

        $payload = $items->map(function (AdminEvent $event) use (
            $registrationsReady,
            $registrationStatusTrackedForViewer,
            $viewerRegsByEvent,
            $viewerId,
        ) {
            /** @var ClientAdminEventRegistration|null $regRow */
            $regRow = $viewerId !== null ? $viewerRegsByEvent->get((string) $event->id) : null;

            $viewerConfirmed = $regRow !== null && (
                ! $registrationStatusTrackedForViewer
                || (string) ($regRow->registration_status ?? '') === 'confirmed'
            );

            return [
                    'id' => $event->id,
                    'source' => 'admin_event',
                    'title' => $event->title,
                    'description' => $event->description,
                    'how_it_works' => $event->how_it_works,
                    'participant_rules' => $event->participant_rules,
                    'image_url' => $event->image_url,
                    'badges' => $event->badges,
                    'location' => $event->location,
                    'category' => $event->category,
                    'location_type' => $event->location_type,
                    'venue' => $event->venue,
                    'registration_starts_at' => $event->registration_starts_at?->toISOString(),
                    'registration_deadline' => $event->registration_deadline?->toISOString(),
                    'participants_count' => $registrationsReady
                        ? (int) ($event->participants_count ?? $event->registrations_count ?? 0)
                        : 0,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'fee_type' => $event->fee_type,
                    'fee' => (float) $event->fee,
                    'running_details' => $event->running_details,
                    'gym_details' => $event->gym_details,
                    'delivery_areas' => RegistrationDeliveryCatalog::resolve(
                        is_array($event->delivery_areas) ? $event->delivery_areas : null
                    ),
                    'published_at' => ($event->publish_at ?? $event->created_at)?->toISOString(),
                    'author' => [
                        'name' => $event->admin?->name ?? 'Administrator',
                        'email' => $event->admin?->email,
                    ],
                    'viewer_registration' => [
                        'registered' => $viewerConfirmed,
                        'confirmed' => $viewerConfirmed,
                        'registration_status' => $regRow?->registration_status,
                        'payment_status' => $regRow?->payment_status,
                        'challenge_progress' => $viewerConfirmed && $viewerId
                            ? $this->challengeProgressSliceForViewer($event, $regRow, (string) $viewerId)
                            : null,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'events' => $payload,
                'total' => $payload->count(),
            ],
        ]);
    }

    public function eventShow(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $now = now('UTC');
        $event = AdminEvent::query()
            ->with('admin:id,name,email')
            ->where('id', $id)
            ->active($now)
            ->first();

        if (! $event) {
            $completedEvent = AdminEvent::query()
                ->where('id', $id)
                ->completed($now)
                ->exists();

            if ($completedEvent) {
                return response()->json([
                    'success' => false,
                    'message' => 'This event has ended. View results on the Race Results page.',
                    'event_status' => 'completed',
                ], 404);
            }

            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $registrationsReady = Schema::hasTable('client_admin_event_registrations');
        $registrationStatusTracked = $registrationsReady
            && Schema::hasColumn('client_admin_event_registrations', 'registration_status');

        $participantsCount = 0;
        /** @var \App\Models\ClientAdminEventRegistration|null $regRow */
        $regRow = null;
        if ($registrationsReady) {
            $participantsQuery = ClientAdminEventRegistration::query()->where('admin_event_id', $event->id);
            if ($registrationStatusTracked) {
                $participantsQuery->where('registration_status', 'confirmed');
            }
            $participantsCount = $participantsQuery->count();

            $regRow = ClientAdminEventRegistration::query()
                ->where('admin_event_id', $event->id)
                ->where('client_id', $request->user()->id)
                ->first();
        }

        $viewerConfirmed = $regRow !== null && (
            ! $registrationStatusTracked
            || (string) ($regRow->registration_status ?? '') === 'confirmed'
        );

        $participantPreviewLimit = 80;
        $participantsPreview = [];
        $participantsTruncated = false;
        $profilesReady = Schema::hasTable('client_profiles');

        if ($registrationsReady && Schema::hasTable('clients') && $participantsCount > 0) {
            $participantsTruncated = $participantsCount > $participantPreviewLimit;

            /** @var \Illuminate\Database\Eloquent\Builder $listQuery */
            $listQuery = ClientAdminEventRegistration::query()
                ->where('admin_event_id', $event->id)
                ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
                ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

            $listQuery->with([
                'client' => function ($q) {
                    $q->select('id')->whereNull('deleted_at');
                },
            ]);

            if ($profilesReady) {
                $listQuery->with([
                    'client.profile' => function ($pq) {
                        $pq->select('id', 'client_id', 'display_name', 'first_name', 'last_name', 'profile_picture_url');
                    },
                ]);
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, ClientAdminEventRegistration> $regs */
            $regs = $listQuery
                ->orderByDesc('created_at')
                ->limit($participantPreviewLimit)
                ->get();

            foreach ($regs as $regRowItem) {
                $clientModel = $regRowItem->client;
                if ($clientModel === null) {
                    continue;
                }

                /** @var ClientProfile|null $pf */
                $pf = $profilesReady ? $clientModel->profile : null;

                $participantsPreview[] = [
                    'client_id' => (string) $clientModel->id,
                    'display_name' => $this->displayNameFromProfile($pf),
                    'initials' => $this->initialsFromRegistrationRow($pf),
                    'profile_picture_url' => $pf ? (string) ($pf->profile_picture_url ?? '') : '',
                    'registered_at' => $regRowItem->created_at?->toISOString(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'source' => 'admin_event',
                    'title' => $event->title,
                    'description' => $event->description,
                    'how_it_works' => $event->how_it_works,
                    'participant_rules' => $event->participant_rules,
                    'image_url' => $event->image_url,
                    'badges' => $event->badges,
                    'location' => $event->location,
                    'category' => $event->category,
                    'location_type' => $event->location_type,
                    'venue' => $event->venue,
                    'registration_starts_at' => $event->registration_starts_at?->toISOString(),
                    'registration_deadline' => $event->registration_deadline?->toISOString(),
                    'participants_count' => $participantsCount,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'fee_type' => $event->fee_type,
                    'fee' => (float) $event->fee,
                    'running_details' => $event->running_details,
                    'gym_details' => $event->gym_details,
                    'delivery_areas' => RegistrationDeliveryCatalog::resolve(
                        is_array($event->delivery_areas) ? $event->delivery_areas : null
                    ),
                    'published_at' => ($event->publish_at ?? $event->created_at)?->toISOString(),
                    'author' => [
                        'name' => $event->admin?->name ?? 'Administrator',
                        'email' => $event->admin?->email,
                    ],
                    'viewer_registration' => [
                        'registered' => $viewerConfirmed,
                        'confirmed' => $viewerConfirmed,
                        'registration_status' => $regRow?->registration_status,
                        'payment_status' => $regRow?->payment_status,
                        'challenge_progress' => $this->challengeProgressSliceForViewer($event, $regRow, (string) $request->user()->id),
                    ],
                    'participants_preview' => $participantsPreview,
                    'participants_truncated' => $participantsTruncated,
                ],
            ],
        ]);
    }

    public function eventLeaderboard(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'category' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:100',
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
            ->active($now)
            ->first();

        if (! $event) {
            $completedEvent = AdminEvent::query()
                ->where('id', $id)
                ->completed($now)
                ->exists();

            if ($completedEvent) {
                return response()->json([
                    'success' => false,
                    'message' => 'This event has ended. View results on the Race Results page.',
                    'event_status' => 'completed',
                ], 404);
            }

            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        if (! Schema::hasTable('client_admin_event_registrations')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'event' => [
                        'id' => (string) $event->id,
                        'title' => (string) $event->title,
                        'category' => (string) $event->category,
                        'location' => (string) $event->location,
                        'starts_at' => $event->starts_at?->toISOString(),
                        'ends_at' => $event->ends_at?->toISOString(),
                        'participants_count' => 0,
                    ],
                    'categories' => [],
                    'leaderboard' => [],
                    'viewer_rank' => null,
                    'total' => 0,
                ],
            ]);
        }

        $categoryFilter = trim((string) $request->input('category', ''));
        $limit = (int) $request->input('limit', 50);
        $viewer = $request->user();
        $registrationStatusTracked = Schema::hasColumn('client_admin_event_registrations', 'registration_status');
        $progressReady = Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');
        $runningSelectionsReady = Schema::hasTable('client_admin_event_running_selections');

        $baseQuery = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        $participantsCount = (clone $baseQuery)->count();

        $listQuery = (clone $baseQuery)
            ->with(['client.profile']);

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

            return [
                'global_rank' => $index + 1,
                'category_key' => $categoryKey,
                'category_label' => $distanceLabel !== '' ? $distanceLabel : 'General',
                'progress' => $progress,
                'user' => $this->mapClientSummaryForLeaderboard($client, $viewer),
                'registered_at' => $reg->created_at?->toISOString(),
            ];
        })->filter()->values();

        $categories = collect($categoryBuckets)
            ->values()
            ->sortBy('label')
            ->values()
            ->all();

        if ($categoryFilter !== '' && $categoryFilter !== 'all') {
            $entries = $entries->filter(
                fn (array $entry) => (string) ($entry['category_key'] ?? '') === $categoryFilter
            )->values();
        }

        $entries = $entries->map(function (array $entry, int $index) {
            $entry['rank'] = $index + 1;

            return $entry;
        })->values();

        $viewerRank = $viewer
            ? $entries->firstWhere('user.id', (string) $viewer->id)
            : null;
        $limitedEntries = $entries->take($limit)->values();

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => (string) $event->id,
                    'title' => (string) $event->title,
                    'category' => (string) $event->category,
                    'location' => (string) $event->location,
                    'image_url' => $event->image_url,
                    'starts_at' => $event->starts_at?->toISOString(),
                    'ends_at' => $event->ends_at?->toISOString(),
                    'participants_count' => $participantsCount,
                    'mileage_challenge_km' => Schema::hasColumn('admin_events', 'mileage_challenge_km')
                        && $event->mileage_challenge_km !== null
                        ? round((float) $event->mileage_challenge_km, 2)
                        : null,
                ],
                'categories' => $categories,
                'leaderboard' => $limitedEntries,
                'viewer_rank' => $viewerRank,
                'total' => $entries->count(),
            ],
        ]);
    }
}

