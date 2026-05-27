<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\WorkoutStatsService;
use Illuminate\Http\JsonResponse;

class BadgeShareController extends Controller
{
    public function __construct(
        private readonly WorkoutStatsService $workoutStatsService,
    ) {
    }

    /**
     * Public badge share card — validates the user actually earned the badge.
     */
    public function show(string $clientId, string $eventId, string $badgeKey): JsonResponse
    {
        $client = Client::query()->with('profile')->find($clientId);
        if (! $client) {
            return response()->json([
                'success' => false,
                'message' => 'Member not found.',
            ], 404);
        }

        $payload = $this->workoutStatsService->resolvePublicBadgeShare($clientId, $eventId, $badgeKey);
        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Badge not found or not yet earned.',
            ], 404);
        }

        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''));
        if (! $displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'Member';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'badge' => $payload,
                'owner' => [
                    'id' => $client->id,
                    'display_name' => $displayName,
                    'profile_picture_url' => $profile?->profile_picture_url,
                ],
                'share_text' => sprintf(
                    '%s earned the "%s" badge in %s on Fitness 365 Pro!',
                    $displayName,
                    $payload['title'] ?: 'Challenge',
                    $payload['event_title'] ?: 'a challenge'
                ),
                'verified' => true,
            ],
        ]);
    }
}
