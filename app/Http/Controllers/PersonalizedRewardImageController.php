<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\ShareOpenGraph;
use App\Services\WorkoutStatsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PersonalizedRewardImageController extends Controller
{
    public function __construct(
        private readonly WorkoutStatsService $workoutStatsService,
    ) {}

    /**
     * Public PNG image: base badge/trophy artwork with the runner's name overlaid.
     * Used by in-app earned rewards + social share previews.
     */
    public function show(Request $request, string $clientId, string $eventId, string $kind, string $rewardKey): Response
    {
        $kind = strtolower(trim($kind));
        $isBadge = $kind === 'badge';
        $isTrophy = $kind === 'trophy';
        if (! $isBadge && ! $isTrophy) {
            return response('', 404);
        }

        $client = Client::query()->with('profile')->find($clientId);
        if (! $client) {
            return response('', 404);
        }

        $displayName = trim((string) ($client->profile?->display_name ?? ''));
        if ($displayName === '') {
            $first = trim((string) ($client->profile?->first_name ?? ''));
            $last = trim((string) ($client->profile?->last_name ?? ''));
            $displayName = trim($first.' '.$last);
        }
        if ($displayName === '') {
            $email = (string) ($client->email ?? '');
            $displayName = $email !== '' ? (explode('@', $email)[0] ?? 'Member') : 'Member';
        }

        $payload = $isBadge
            ? $this->workoutStatsService->resolvePublicBadgeShare($clientId, $eventId, $rewardKey)
            : $this->workoutStatsService->resolvePublicTrophyShare($clientId, $eventId, $rewardKey);

        if (! $payload) {
            return response('', 404);
        }

        // Avoid recursion: always use the *base* artwork URL (not already-personalized).
        $baseUrl = (string) ($payload['base_image_url'] ?? $payload['image_url'] ?? '');
        $absoluteBaseUrl = ShareOpenGraph::absoluteImageUrl($baseUrl);

        $binary = $this->fetchImageBytes($absoluteBaseUrl);
        if ($binary === null) {
            // Fallback: if we can't render, return the original asset.
            return response('', 302)->header('Location', $absoluteBaseUrl);
        }

        [$png, $renderMode] = $this->renderNameOverlayPng($binary, $displayName);
        if ($png === null) {
            // If we cannot decode/rasterize the base image (e.g. SVG without rsvg, unsupported WEBP),
            // fall back to the original asset rather than breaking the UI.
            return response('', 302)->header('Location', $absoluteBaseUrl)->header('X-Reward-Render', $renderMode);
        }

        $etag = '"'.sha1($png).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('X-Reward-Render', $renderMode);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'ETag' => $etag,
            'X-Reward-Render' => $renderMode,
        ]);
    }

    /**
     * SVG fallback that requires no GD/Imagick. Renders the base artwork and overlays the name.
     * This is primarily for local/XAMPP environments where PHP image extensions may be missing.
     */
    public function showSvg(Request $request, string $clientId, string $eventId, string $kind, string $rewardKey): Response
    {
        $kind = strtolower(trim($kind));
        $isBadge = $kind === 'badge';
        $isTrophy = $kind === 'trophy';
        if (! $isBadge && ! $isTrophy) {
            return response('', 404);
        }

        $client = Client::query()->with('profile')->find($clientId);
        if (! $client) {
            return response('', 404);
        }

        $displayName = trim((string) ($client->profile?->display_name ?? ''));
        if ($displayName === '') {
            $first = trim((string) ($client->profile?->first_name ?? ''));
            $last = trim((string) ($client->profile?->last_name ?? ''));
            $displayName = trim($first.' '.$last);
        }
        if ($displayName === '') {
            $email = (string) ($client->email ?? '');
            $displayName = $email !== '' ? (explode('@', $email)[0] ?? 'Member') : 'Member';
        }

        $payload = $isBadge
            ? $this->workoutStatsService->resolvePublicBadgeShare($clientId, $eventId, $rewardKey)
            : $this->workoutStatsService->resolvePublicTrophyShare($clientId, $eventId, $rewardKey);

        if (! $payload) {
            return response('', 404);
        }

        $baseUrl = (string) ($payload['base_image_url'] ?? $payload['image_url'] ?? '');
        $absoluteBaseUrl = ShareOpenGraph::absoluteImageUrl($baseUrl);

        $svg = $this->renderSvgOverlay($absoluteBaseUrl, $displayName);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // Don't cache too aggressively in dev; name/display can change.
            'Cache-Control' => 'public, max-age=300',
            'X-Reward-Render' => 'svg',
        ]);
    }

    private function fetchImageBytes(string $url): ?string
    {
        try {
            $res = Http::timeout(8)->retry(1, 150)->get($url);
            if (! $res->ok()) {
                return null;
            }
            $body = $res->body();
            return is_string($body) && $body !== '' ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: string|null, 1: string} [pngBytes, renderMode]
     */
    private function renderNameOverlayPng(string $sourceBytes, string $name): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return [null, 'gd_missing'];
        }

        $img = $this->decodeToGdImage($sourceBytes);
        if (! $img) {
            return [null, 'decode_failed'];
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if (! $w || ! $h) {
            imagedestroy($img);
            return [null, 'invalid_dimensions'];
        }

        // Ensure alpha is preserved.
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Draw a semi-transparent ribbon near the bottom (lifted a bit so it
        // remains visible even when the client shows the image in smaller sizes).
        $ribbonHeight = (int) max(52, min(96, round($h * 0.22)));
        $bottomInset = (int) max(10, round($h * 0.04));
        $y0 = max(0, $h - $ribbonHeight - $bottomInset);
        $bg = imagecolorallocatealpha($img, 10, 15, 25, 52); // ~80% opaque
        imagefilledrectangle($img, 0, $y0, $w, min($h, $y0 + $ribbonHeight), $bg);

        $text = trim($name);
        if ($text === '') {
            $text = 'Member';
        }

        // Clamp length for layout.
        if (mb_strlen($text) > 26) {
            $text = mb_substr($text, 0, 24).'…';
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 80);

        // Built-in GD fonts are tiny when the client downscales images.
        // Create a small text bitmap then upscale it to the desired height.
        $font = 5; // largest built-in
        $fh = imagefontheight($font);
        $fw = imagefontwidth($font);
        $rawTextW = max(1, $fw * strlen($text));
        $rawTextH = max(1, $fh);

        $targetTextH = (int) max(22, min(44, floor($ribbonHeight * 0.6)));
        $scale = max(1, (int) ceil($targetTextH / $rawTextH));
        $scaledW = (int) max(1, $rawTextW * $scale);
        $scaledH = (int) max(1, $rawTextH * $scale);

        $tmp = imagecreatetruecolor($rawTextW + 2, $rawTextH + 2);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, imagesx($tmp), imagesy($tmp), $transparent);

        $tmpShadow = imagecolorallocatealpha($tmp, 0, 0, 0, 70);
        $tmpWhite = imagecolorallocatealpha($tmp, 255, 255, 255, 0);
        imagestring($tmp, $font, 1, 1, $text, $tmpShadow);
        imagestring($tmp, $font, 0, 0, $text, $tmpWhite);

        $scaled = imagecreatetruecolor($scaledW, $scaledH);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        $transparent2 = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefilledrectangle($scaled, 0, 0, $scaledW, $scaledH, $transparent2);
        imagecopyresampled($scaled, $tmp, 0, 0, 0, 0, $scaledW, $scaledH, imagesx($tmp), imagesy($tmp));

        imagedestroy($tmp);

        // Center inside the ribbon.
        $x = (int) max(8, floor(($w - $scaledW) / 2));
        $y = (int) max(8, floor($y0 + ($ribbonHeight - $scaledH) / 2));
        imagecopy($img, $scaled, $x, $y, 0, 0, $scaledW, $scaledH);
        imagedestroy($scaled);

        ob_start();
        imagepng($img, null, 8);
        $out = ob_get_clean();
        imagedestroy($img);

        return [is_string($out) && $out !== '' ? $out : null, 'ok'];
    }

    private function decodeToGdImage(string $bytes)
    {
        $img = @imagecreatefromstring($bytes);
        if ($img) {
            return $img;
        }

        // Fallback: try to rasterize via Imagick if available (handles WEBP on many installs;
        // can also handle SVG if the server has the right delegates).
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $im = new \Imagick();
            $im->readImageBlob($bytes);
            $im->setImageFormat('png');
            $png = $im->getImagesBlob();
            $im->clear();
            $im->destroy();

            if (! is_string($png) || $png === '') {
                return false;
            }

            return @imagecreatefromstring($png);
        } catch (\Throwable) {
            return false;
        }
    }

    private function renderSvgOverlay(string $absoluteBaseUrl, string $name): string
    {
        $safeName = trim($name) !== '' ? trim($name) : 'Member';
        if (mb_strlen($safeName) > 34) {
            $safeName = mb_substr($safeName, 0, 32).'…';
        }

        // Basic XML escaping.
        $safeNameEsc = htmlspecialchars($safeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $imgEsc = htmlspecialchars($absoluteBaseUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Square artboard so it looks good in the app tiles and modal.
        // Ribbon is near bottom with large readable text.
        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" viewBox="0 0 1200 1200">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0b1220"/>
      <stop offset="1" stop-color="#111827"/>
    </linearGradient>
    <filter id="shadow" x="-20%" y="-20%" width="140%" height="140%">
      <feDropShadow dx="0" dy="10" stdDeviation="18" flood-color="#000" flood-opacity="0.35"/>
    </filter>
  </defs>

  <rect width="1200" height="1200" fill="url(#bg)"/>

  <!-- Artwork (contained, not cropped) -->
  <image href="{$imgEsc}" x="90" y="70" width="1020" height="960" preserveAspectRatio="xMidYMid meet" />

  <!-- Name ribbon -->
  <g filter="url(#shadow)">
    <rect x="120" y="980" width="960" height="150" rx="28" fill="rgba(10,15,25,0.78)" />
  </g>
  <text x="600" y="1078" text-anchor="middle"
        font-family="Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif"
        font-size="64" font-weight="800" fill="#ffffff"
        style="paint-order: stroke; stroke: rgba(0,0,0,0.55); stroke-width: 6px;">
    {$safeNameEsc}
  </text>
</svg>
SVG;
    }
}

