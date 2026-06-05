<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Ranks confirmed registrants who reached 100% progress (same ordering as the public event leaderboard).
 */
class EventFinisherRankService
{
    public function __construct(
        private readonly EventLeaderboardRankingService $leaderboardRanking,
    ) {}
    /**
     * 1-based rank among event finishers, or null if the client did not finish.
     */
    public function finisherRankForClient(string $eventId, string $clientId): ?int
    {
        if (! Schema::hasTable('client_admin_event_registrations')
            || ! Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km')) {
            return null;
        }

        $event = AdminEvent::query()->find($eventId);
        if (! $event) {
            return null;
        }

        $viewerReg = $this->confirmedRegistrationQuery($eventId)
            ->where('client_id', $clientId)
            ->first();

        if (! $viewerReg || ! $this->leaderboardRanking->registrationIsFinisher($event, $viewerReg)) {
            return null;
        }

        $regsQuery = $this->confirmedRegistrationQuery($eventId);
        $this->leaderboardRanking->applyParticipationFilter(
            $regsQuery,
            $eventId,
            Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km'),
        );
        $regs = $regsQuery->get();
        $finisherByClient = $this->leaderboardRanking->buildFinisherLookup($event, $regs);

        $finisherIds = $regs
            ->filter(fn (ClientAdminEventRegistration $reg) => $finisherByClient[(string) $reg->client_id] ?? false)
            ->sort(function (ClientAdminEventRegistration $a, ClientAdminEventRegistration $b) use ($event, $finisherByClient) {
                return $this->leaderboardRanking->compareRegistrations($event, $a, $b, $finisherByClient);
            })
            ->values()
            ->pluck('client_id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        $position = array_search((string) $clientId, $finisherIds, true);

        return $position === false ? null : $position + 1;
    }

    private function confirmedRegistrationQuery(string $eventId): Builder
    {
        $query = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $eventId)
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'));

        if (Schema::hasColumn('client_admin_event_registrations', 'registration_status')) {
            $query->where('registration_status', 'confirmed');
        }

        return $query;
    }
}
