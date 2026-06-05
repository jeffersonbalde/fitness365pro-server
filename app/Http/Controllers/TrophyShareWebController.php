<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\ShareOpenGraph;
use App\Services\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrophyShareWebController extends Controller
{
    public function __construct(
        private readonly WorkoutStatsService $workoutStatsService,
    ) {}

    /**
     * Public share landing page with Open Graph meta tags for earned trophies.
     */
    public function show(Request $request, string $clientId, string $eventId, string $trophyKey): View
    {
        $client = Client::query()->with('profile')->find($clientId);
        $payload = $client
            ? $this->workoutStatsService->resolvePublicTrophyShare($clientId, $eventId, $trophyKey)
            : null;

        if (! $client || $payload === null) {
            return view('share.badge-not-found', [
                'pageTitle' => 'Trophy not found — Fitness 365 Pro',
                'frontendUrl' => rtrim((string) config('app.frontend_url'), '/'),
            ]);
        }

        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? ''));
        if (! $displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'Member';
        }

        $trophyTitle = (string) ($payload['title'] ?: 'Challenge Trophy');
        $eventTitle = (string) ($payload['event_title'] ?: 'Fitness 365 Pro Challenge');
        $shareText = sprintf(
            '%s won the "%s" trophy in %s on Fitness 365 Pro!',
            $displayName,
            $trophyTitle,
            $eventTitle,
        );

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $canonicalUrl = rtrim($shareOrigin, '/').'/share/trophy/'
            .rawurlencode($clientId).'/'
            .rawurlencode($eventId).'/'
            .rawurlencode((string) ($payload['trophy_key'] ?? $trophyKey));
        $imageUrl = ShareOpenGraph::rewardOgImageUrl($payload);
        $ogImageType = ShareOpenGraph::imageMimeTypeForUrl($imageUrl);
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $clientAppUrl = sprintf(
            '%s/profile/%s',
            $frontendBase,
            rawurlencode($clientId),
        );

        return view('share.badge', [
            'pageTitle' => "{$trophyTitle} — {$displayName} | Fitness 365 Pro",
            'ogTitle' => "{$trophyTitle} — {$displayName}",
            'ogDescription' => $shareText,
            'ogImageAlt' => "{$trophyTitle} trophy",
            'ogImageType' => $ogImageType,
            'shareText' => $shareText,
            'badgeTitle' => $trophyTitle,
            'eventTitle' => $eventTitle,
            'ownerName' => $displayName,
            'canonicalUrl' => $canonicalUrl,
            'imageUrl' => $imageUrl,
            'clientAppUrl' => $clientAppUrl,
            'frontendUrl' => $frontendBase,
        ]);
    }
}
