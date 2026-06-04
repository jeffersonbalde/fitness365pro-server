<?php

namespace App\Http\Controllers;

use App\Services\EventLeaderboardShareService;
use App\Support\LeaderboardShareCardSvgBuilder;
use App\Support\ShareOpenGraph;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class LeaderboardShareImageController extends Controller
{
    public function __construct(
        private readonly EventLeaderboardShareService $leaderboardShare,
        private readonly LeaderboardShareCardSvgBuilder $cardBuilder,
    ) {}

    public function show(Request $request, string $eventId, string $clientId): Response
    {
        try {
            $category = trim((string) $request->query('category', 'all'));
            $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

            if ($payload === null) {
                return response('', 404);
            }

            $png = $this->cardBuilder->toPng($payload);
            if ($png === null || $png === '') {
                $fallback = ShareOpenGraph::absoluteImageUrl((string) ($payload['event_image_url'] ?? ''));
                if ($fallback !== ShareOpenGraph::defaultImageUrl()) {
                    return $this->proxyRasterImage($fallback);
                }

                return response('', 404);
            }

            $etag = '"'.sha1($png).'"';
            if ($request->headers->get('If-None-Match') === $etag) {
                return response('', 304)->header('ETag', $etag);
            }

            return response($png, 200, $this->imageHeaders('image/png', $etag));
        } catch (\Throwable $e) {
            report($e);

            return response('', 500);
        }
    }

    public function showSvg(Request $request, string $eventId, string $clientId): Response
    {
        $category = trim((string) $request->query('category', 'all'));
        $payload = $this->leaderboardShare->resolvePublicStanding($eventId, $clientId, $category);

        if ($payload === null) {
            return response('', 404);
        }

        return response($this->cardBuilder->build($payload), 200, $this->imageHeaders('image/svg+xml'));
    }

    /**
     * @return array<string, string>
     */
    private function imageHeaders(string $contentType, ?string $etag = null): array
    {
        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ];

        if ($etag !== null) {
            $headers['ETag'] = $etag;
        }

        return $headers;
    }

    private function proxyRasterImage(string $url): Response
    {
        try {
            $response = Http::timeout(15)->get($url);
            if (! $response->successful()) {
                return response('', 404);
            }

            $body = $response->body();
            if ($body === '') {
                return response('', 404);
            }

            return response($body, 200, $this->imageHeaders(ShareOpenGraph::imageMimeTypeForUrl($url)));
        } catch (\Throwable) {
            return response('', 404);
        }
    }
}
