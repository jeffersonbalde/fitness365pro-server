<?php

namespace App\Http\Controllers;

use App\Services\EventLeaderboardShareService;
use App\Support\ShareOpenGraph;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaderboardShareWebController extends Controller
{
    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
    ) {}

    /**
     * Public share landing page with Open Graph meta for leaderboard standing.
     */
    public function show(Request $request, string $eventId, string $clientId): View
    {
        $category = trim((string) $request->query('category', 'all'));
        $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

        $frontendBase = rtrim((string) config('app.frontend_url'), '/');

        if ($payload === null) {
            $shareOrigin = ShareOpenGraph::shareOrigin();
            $canonicalUrl = rtrim($shareOrigin, '/').'/share/leaderboard/'
                .rawurlencode($eventId).'/'
                .rawurlencode($clientId);

            return view('share.leaderboard-not-found', [
                'pageTitle' => 'Leaderboard standing not found — Fitness 365 Pro',
                'frontendUrl' => $frontendBase,
                'ogTitle' => 'Fitness 365 Pro Leaderboard',
                'ogDescription' => 'View live event leaderboards and rankings on Fitness 365 Pro.',
                'canonicalUrl' => $canonicalUrl,
                'imageUrl' => ShareOpenGraph::defaultImageUrl(),
                'ogImageType' => 'image/jpeg',
            ]);
        }

        $displayName = (string) $payload['display_name'];
        $eventTitle = (string) $payload['event_title'];
        $rank = (int) $payload['rank'];
        $categoryLabel = (string) ($payload['category_label'] ?? '');
        $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
        $logged = (float) ($progress['logged_distance_km'] ?? 0);
        $percent = $progress['progress_percent'] ?? null;
        $goalCompleted = (bool) ($progress['goal_completed'] ?? false);

        $statsLine = sprintf(
            '%s km logged%s',
            number_format($logged, 2),
            $goalCompleted
                ? ' · Goal completed'
                : ($percent !== null ? ' · '.number_format((float) $percent, 1).'% of goal' : ''),
        );

        $shareText = sprintf(
            '%s is ranked #%d on the "%s" leaderboard on Fitness 365 Pro! %s%s',
            $displayName,
            $rank,
            $eventTitle,
            $statsLine,
            $categoryLabel !== '' && $categoryLabel !== 'General' ? " ({$categoryLabel})" : '',
        );

        $shareOrigin = ShareOpenGraph::shareOrigin();
        $categoryQuery = $category !== '' && $category !== 'all'
            ? '?category='.rawurlencode($category)
            : '';
        $canonicalUrl = rtrim($shareOrigin, '/').'/share/leaderboard/'
            .rawurlencode($eventId).'/'
            .rawurlencode($clientId).$categoryQuery;

        $imageUrl = ShareOpenGraph::leaderboardOgImageUrl($payload);
        $ogImageType = ShareOpenGraph::imageMimeTypeForUrl($imageUrl);

        $clientAppUrl = $frontendBase.'/leaderboard/'
            .rawurlencode($eventId).'/'
            .rawurlencode($clientId).$categoryQuery;

        return view('share.leaderboard', [
            'pageTitle' => "#{$rank} — {$displayName} | {$eventTitle} Leaderboard",
            'ogTitle' => "#{$rank} on {$eventTitle} — Fitness 365 Pro",
            'ogDescription' => $shareText,
            'ogImageAlt' => "{$displayName} leaderboard rank #{$rank}",
            'shareText' => $shareText,
            'displayName' => $displayName,
            'eventTitle' => $eventTitle,
            'rank' => $rank,
            'categoryLabel' => $categoryLabel,
            'statsLine' => $statsLine,
            'canonicalUrl' => $canonicalUrl,
            'imageUrl' => $imageUrl,
            'ogImageType' => $ogImageType,
            'clientAppUrl' => $clientAppUrl,
            'frontendUrl' => $frontendBase,
        ]);
    }
}
