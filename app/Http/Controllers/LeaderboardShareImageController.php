<?php

namespace App\Http\Controllers;

use App\Services\EventLeaderboardShareService;
use App\Support\LeaderboardShareCardSvgBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LeaderboardShareImageController extends Controller
{
    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
        private readonly LeaderboardShareCardSvgBuilder $cardBuilder,
    ) {}

    public function show(Request $request, string $eventId, string $clientId): Response
    {
        $category = trim((string) $request->query('category', 'all'));
        $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

        if ($payload === null) {
            return response('', 404);
        }

        $png = $this->cardBuilder->toPng($payload);
        if ($png === null) {
            return response('', 404);
        }

        $etag = '"'.sha1($png).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
        ]);
    }

    public function showSvg(Request $request, string $eventId, string $clientId): Response
    {
        $category = trim((string) $request->query('category', 'all'));
        $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

        if ($payload === null) {
            return response('', 404);
        }

        return response($this->cardBuilder->build($payload), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
