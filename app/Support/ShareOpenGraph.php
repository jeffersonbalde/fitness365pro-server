<?php

namespace App\Support;

/**
 * Absolute HTTPS URLs and defaults for public /share/* Open Graph pages.
 */
final class ShareOpenGraph
{
    public static function shareOrigin(): string
    {
        return rtrim((string) config('app.share_url', config('app.url')), '/');
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
