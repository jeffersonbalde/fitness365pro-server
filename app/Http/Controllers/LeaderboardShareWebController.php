<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersLeaderboardShareOgPage;
use App\Services\EventLeaderboardShareService;
use App\Support\ShareOpenGraph;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LeaderboardShareWebController extends Controller
{
    use RendersLeaderboardShareOgPage;

    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
    ) {}

    /**
     * Public share landing page with Open Graph meta for leaderboard standing.
     */
    public function show(Request $request, string $eventId, string $clientId): View
    {
        $category = trim((string) $request->query('category', 'all'));
        $categoryQuery = $category !== '' && $category !== 'all'
            ? '?category='.rawurlencode($category)
            : '';
        $shareOrigin = ShareOpenGraph::shareOrigin();
        $canonicalUrl = rtrim($shareOrigin, '/').'/share/leaderboard/'
            .rawurlencode($eventId).'/'
            .rawurlencode($clientId).$categoryQuery;

        return $this->renderLeaderboardShareOgPage(
            $request,
            $this->leaderboardShare,
            $eventId,
            $clientId,
            $canonicalUrl,
        );
    }
}
