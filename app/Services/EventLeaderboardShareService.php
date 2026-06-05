<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\ClientProfile;
use App\Support\PublicUploadStorage;
use App\Support\ShareOpenGraph;
use App\Support\ViewerChallengeProgressPresenter;
use Illuminate\Support\Facades\Schema;

class EventLeaderboardShareService
{
    /**
     * Resolve a public leaderboard standing for social share (OG + API).
     *
     * @return array<string, mixed>|null
     */
    public function resolvePublicStanding(string $eventId, string $clientId, string $categoryFilter = 'all'): ?array
    {
        if (! Schema::hasTable('admin_events') || ! Schema::hasTable('client_admin_event_registrations')) {
            return null;
        }

        $now = now('UTC');
        $event = AdminEvent::query()
            ->where('id', $eventId)
            ->publishedForRegistrants($now)
            ->first();

        if (! $event) {
            return null;
        }

        $client = Client::query()->with('profile')->find($clientId);
        if (! $client) {
            return null;
        }

        $registrationStatusTracked = Schema::hasColumn('client_admin_event_registrations', 'registration_status');
        $progressReady = Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km');
        $runningSelectionsReady = Schema::hasTable('client_admin_event_running_selections');

        $baseQuery = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->where('client_id', $client->id)
            ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        $reg = (clone $baseQuery)->with(['client.profile'])->first();
        if (! $reg) {
            return null;
        }

        $categoryFilter = trim($categoryFilter) !== '' ? trim($categoryFilter) : 'all';

        $filteredQuery = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        $this->applyLeaderboardCategoryFilter($filteredQuery, $categoryFilter, (string) $event->id, $runningSelectionsReady);

        $rank = $this->viewerLeaderboardRank($filteredQuery, $reg, $progressReady, $event);

        $selections = collect();
        if ($runningSelectionsReady) {
            $selections = ClientAdminEventRunningSelection::query()
                ->where('admin_event_id', $event->id)
                ->where('client_id', $client->id)
                ->get()
                ->keyBy(static fn ($row) => (string) $row->client_id);
        }

        $progress = $progressReady
            ? $this->progressMetricsForRegistration($event, $reg)
            : [
                'logged_distance_km' => 0.0,
                'goal_distance_km' => null,
                'progress_percent' => null,
                'pace_min_per_km' => null,
                'goal_completed' => false,
            ];

        $selection = $selections->get((string) $client->id);
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

        $displayName = $this->displayNameFromProfile($client->profile);
        if ($displayName === 'Fitness 365 member' && $client->email) {
            $displayName = explode('@', (string) $client->email)[0] ?? 'Member';
        }

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $categoryQuery = $categoryFilter !== 'all' ? '?category='.rawurlencode($categoryFilter) : '';
        $cardPath = '/share/leaderboard/'.rawurlencode($eventId).'/'.rawurlencode($clientId).'/card.png'.$categoryQuery;

        $participantsCount = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->when($registrationStatusTracked, fn ($q) => $q->where('registration_status', 'confirmed'))
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'))
            ->count();

        return [
            'client_id' => (string) $client->id,
            'event_id' => (string) $event->id,
            'category_key' => $distanceKey !== '' ? $distanceKey : '_general',
            'category_filter' => $categoryFilter,
            'category_label' => $distanceLabel !== '' ? $distanceLabel : 'General',
            'rank' => $rank,
            'display_name' => $displayName,
            'profile_picture_url' => PublicUploadStorage::resolveForClient($client->profile?->profile_picture_url),
            'event_title' => (string) $event->title,
            'event_image_url' => PublicUploadStorage::resolveForClient($event->image_url),
            'event_location' => (string) ($event->location ?? ''),
            'participants_count' => $participantsCount,
            'progress' => $progress,
            'share_card_url' => rtrim($shareOrigin, '/').$cardPath,
            'share_page_url' => rtrim($shareOrigin, '/').'/share/leaderboard/'
                .rawurlencode($eventId).'/'
                .rawurlencode($clientId).$categoryQuery,
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

    private function applyLeaderboardOrdering($query, bool $progressReady): void
    {
        app(EventLeaderboardRankingService::class)->applySqlOrdering($query, $progressReady);
    }

    private function applyLeaderboardCategoryFilter(
        $query,
        string $categoryFilter,
        string $eventId,
        bool $runningSelectionsReady,
    ): void {
        if ($categoryFilter === '' || $categoryFilter === 'all' || ! $runningSelectionsReady) {
            return;
        }

        if ($categoryFilter === '_general') {
            $query->whereNotIn('client_id', ClientAdminEventRunningSelection::query()
                ->where('admin_event_id', $eventId)
                ->whereNotNull('distance_key')
                ->where('distance_key', '!=', '')
                ->select('client_id'));

            return;
        }

        $query->whereIn('client_id', ClientAdminEventRunningSelection::query()
            ->where('admin_event_id', $eventId)
            ->where('distance_key', $categoryFilter)
            ->select('client_id'));
    }

    private function viewerLeaderboardRank(
        $baseQuery,
        ClientAdminEventRegistration $viewerReg,
        bool $progressReady,
        AdminEvent $event,
    ): int {
        return app(EventLeaderboardRankingService::class)
            ->rankForRegistration($event, $baseQuery, $viewerReg, $progressReady);
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
}
