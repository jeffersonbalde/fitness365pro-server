<?php

namespace App\Support;

/**
 * Absolute HTTPS URLs and defaults for public /share/* Open Graph pages.
 */
final class ShareOpenGraph
{
    public static function shareOrigin(): string
    {
        $configured = rtrim((string) config('app.share_url', config('app.url')), '/');

        if ($configured !== '' && ! self::isUnusableForPublicCrawlers($configured)) {
            return self::ensureHttps($configured);
        }

        if (! app()->runningInConsole() && request()->getHost() !== '') {
            return self::ensureHttps(request()->getSchemeAndHttpHost());
        }

        return self::ensureHttps($configured);
    }

    private static function isUnusableForPublicCrawlers(string $origin): bool
    {
        if (! app()->environment('production')) {
            return false;
        }

        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local');
    }

    private static function ensureHttps(string $url): string
    {
        if (app()->environment('production') && str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }

    public static function absoluteImageUrl(?string $url): string
    {
        $resolved = PublicUploadStorage::resolveForClient($url);
        if ($resolved === '') {
            return self::defaultImageUrl();
        }

        if (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://')) {
            return str_starts_with($resolved, 'http://')
                ? 'https://'.substr($resolved, 7)
                : $resolved;
        }

        return self::shareOrigin().'/'.ltrim($resolved, '/');
    }

    public static function defaultImageUrl(): string
    {
        $origin = self::shareOrigin();

        return "{$origin}/logo.jpg";
    }

    public static function facebookAppId(): string
    {
        return trim((string) config('services.facebook.app_id', ''));
    }

    /**
     * Facebook scrapes direct CDN images reliably (same as event shares).
     * Rank card PNG is used in-page; card URL remains for Feed Dialog picture=.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function leaderboardOgImageUrl(array $payload): string
    {
        $eventImage = self::absoluteImageUrl((string) ($payload['event_image_url'] ?? ''));
        if ($eventImage !== self::defaultImageUrl()) {
            return $eventImage;
        }

        $card = (string) ($payload['share_card_url'] ?? '');
        if ($card !== '') {
            return $card;
        }

        return self::defaultImageUrl();
    }

    public static function imageMimeTypeForUrl(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return match (true) {
            str_ends_with($path, '.png') => 'image/png',
            str_ends_with($path, '.webp') => 'image/webp',
            str_ends_with($path, '.gif') => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
