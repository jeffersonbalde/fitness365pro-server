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
}
