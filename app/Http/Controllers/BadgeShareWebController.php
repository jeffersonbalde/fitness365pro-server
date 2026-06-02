<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\ShareOpenGraph;
use App\Services\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
    
class BadgeShareWebController extends Controller
{
    public function __construct(
        private readonly WorkoutStatsService $workoutStatsService,
    ) {
    }


    

    /**
     * Public share landing page with Open Graph meta tags for Facebook/social crawlers.
     */
    public function show(Request $request, string $clientId, string $eventId, string $badgeKey): View
    {
        $client = Client::query()->with('profile')->find($clientId);
        $payload = $client
            ? $this->workoutStatsService->resolvePublicBadgeShare($clientId, $eventId, $badgeKey)
            : null;

        if (! $client || $payload === null) {
            return view('share.badge-not-found', [
                'pageTitle' => 'Badge not found — Fitness 365 Pro',
                'frontendUrl' => rtrim((string) config('app.frontend_url'), '/'),
            ]);
        }

        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''));
        if (! $displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'Member';
        }

        $badgeTitle = (string) ($payload['title'] ?: 'Challenge Badge');
        $eventTitle = (string) ($payload['event_title'] ?: 'Fitness 365 Pro Challenge');
        $shareText = sprintf(
            '%s earned the "%s" badge in %s on Fitness 365 Pro!',
            $displayName,
            $badgeTitle,
            $eventTitle,
        );

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $canonicalUrl = rtrim($shareOrigin, '/').'/share/badge/'
            .rawurlencode($clientId).'/'
            .rawurlencode($eventId).'/'
            .rawurlencode((string) ($payload['badge_key'] ?? $badgeKey));
        $imageUrl = ShareOpenGraph::absoluteImageUrl((string) ($payload['image_url'] ?? ''));
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $clientAppUrl = sprintf(
            '%s/badge/%s/%s/%s',
            $frontendBase,
            rawurlencode($clientId),
            rawurlencode($eventId),
            rawurlencode((string) ($payload['badge_key'] ?? $badgeKey)),
        );

        return view('share.badge', [
            'pageTitle' => "{$badgeTitle} — {$displayName} | Fitness 365 Pro",
            'shareText' => $shareText,
            'badgeTitle' => $badgeTitle,
            'eventTitle' => $eventTitle,
            'ownerName' => $displayName,
            'canonicalUrl' => $canonicalUrl,
            'imageUrl' => $imageUrl,
            'clientAppUrl' => $clientAppUrl,
            'frontendUrl' => $frontendBase,
        ]);
    }

}
