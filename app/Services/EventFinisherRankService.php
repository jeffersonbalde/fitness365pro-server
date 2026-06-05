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

        $rankedQuery = $this->confirmedRegistrationQuery($eventId);
        $this->leaderboardRanking->applyParticipationFilter(
            $rankedQuery,
            $eventId,
            Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km'),
        );

        return $this->leaderboardRanking->rankForRegistration(
            $event,
            $rankedQuery,
            $viewerReg,
            true,
        );
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
