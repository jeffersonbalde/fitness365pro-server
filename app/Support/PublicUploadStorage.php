<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * User-uploaded media (profile photos, workout images, admin CMS assets).
 *
 * Local dev uses the "public" disk; production should set UPLOAD_DISK=s3 with
 * DigitalOcean Spaces credentials so files survive App Platform redeploys.
 */
final class PublicUploadStorage
{
    public const ALLOWED_DIRECTORIES = [
        'profile-pictures',
        'cover-photos',
        'workout-photos',
        'profile-badges',
        'admin-events',
        'admin-event-badges',
    ];

    public static function diskName(): string
    {
        return (string) config('filesystems.upload_disk', 'public');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function isRemote(): bool
    {
        return self::diskName() !== 'public';
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        if (self::isRemote()) {
            return $file->storePublicly($directory, self::diskName());
        }

        return $file->store($directory, self::diskName());
    }

    public static function publicUrl(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if (self::isRemote()) {
            return self::disk()->url($relativePath);
        }

        $encodedPath = collect(explode('/', $relativePath))
            ->map(fn ($segment) => rawurlencode($segment))
            ->implode('/');

        return "/api/v1/profile/media/{$encodedPath}";
    }

    public static function storePublicReference(UploadedFile $file, string $directory): string
    {
        return self::publicUrl(self::store($file, $directory));
    }

    public static function extractRelativePath(?string $url, ?array $allowedDirectories = null): ?string
    {
        $trimmed = trim((string) $url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $trimmed)) {
            $path = parse_url($trimmed, PHP_URL_PATH);
            $path = ltrim(rawurldecode((string) $path), '/');
        } else {
            $path = parse_url($trimmed, PHP_URL_PATH);
            if (! is_string($path) || $path === '') {
                $path = $trimmed;
            }

            $path = ltrim($path, '/');
        }

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        } elseif (str_starts_with($path, 'api/v1/profile/media/')) {
            $path = substr($path, strlen('api/v1/profile/media/'));
        }

        $path = rawurldecode($path);

        $allowed = $allowedDirectories ?? self::ALLOWED_DIRECTORIES;
        foreach ($allowed as $directory) {
            $prefix = trim($directory, '/').'/';
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }

        return null;
    }

    public static function deleteByUrl(?string $url, ?array $allowedDirectories = null): void
    {
        $relativePath = self::extractRelativePath($url, $allowedDirectories);
        if ($relativePath === null) {
            return;
        }

        if (self::disk()->exists($relativePath)) {
            self::disk()->delete($relativePath);
        }
    }

    public static function isAllowedMediaPath(string $relativePath): bool
    {
        return self::extractRelativePath($relativePath) !== null;
    }

    public static function normalizePublicUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            $relativePath = self::extractRelativePath($url);
            if ($relativePath !== null) {
                return self::publicUrl($relativePath);
            }

            return $url;
        }

        $relativePath = self::extractRelativePath($url);
        if ($relativePath !== null) {
            return self::publicUrl($relativePath);
        }

        return $url;
    }

    public static function resolveForClient(?string $url): string
    {
        if (! is_string($url) || trim($url) === '') {
            return '';
        }

        $normalized = self::normalizePublicUrl($url);

        return is_string($normalized) ? trim($normalized) : '';
    }
}
