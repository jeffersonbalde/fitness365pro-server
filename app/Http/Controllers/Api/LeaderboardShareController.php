<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventLeaderboardShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardShareController extends Controller
{
    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
    ) {}

    /**
     * Public leaderboard standing — validates confirmed registration + rank.
     */
    public function show(Request $request, string $eventId, string $clientId): JsonResponse
    {
        $category = trim((string) $request->query('category', 'all'));
        $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Leaderboard standing not found or the event is unavailable for sharing.',
            ], 404);
        }

        $rank = (int) $payload['rank'];
        $displayName = (string) $payload['display_name'];
        $eventTitle = (string) $payload['event_title'];

        return response()->json([
            'success' => true,
            'data' => [
                'standing' => $payload,
                'share_text' => sprintf(
                    '%s is ranked #%d on the "%s" leaderboard on Fitness 365 Pro!',
                    $displayName,
                    $rank,
                    $eventTitle,
                ),
                'verified' => true,
            ],
        ]);
    }
}
