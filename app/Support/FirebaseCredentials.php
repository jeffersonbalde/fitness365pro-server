<?php

namespace App\Support;

use InvalidArgumentException;

class FirebaseCredentials
{
    /**
     * Resolve Firebase service account credentials from config (not env() directly).
     *
     * @return array<string, mixed>|non-empty-string
     */
    public static function resolve(): array|string
    {
        $json = config('services.firebase.credentials_json');
        if (is_string($json) && trim($json) !== '') {
            return self::decodeJson(trim($json), 'FIREBASE_CREDENTIALS_JSON');
        }

        $base64 = config('services.firebase.credentials_base64');
        if (is_string($base64) && trim($base64) !== '') {
            $raw = base64_decode(trim($base64), true);
            if ($raw === false || $raw === '') {
                throw new InvalidArgumentException('FIREBASE_CREDENTIALS_BASE64 is not valid base64.');
            }

            return self::decodeJson($raw, 'FIREBASE_CREDENTIALS_BASE64');
        }

        $path = config('services.firebase.credentials_path');
        if (is_string($path) && trim($path) !== '') {
            $absolute = self::absolutePath(trim($path));
            if (! is_file($absolute)) {
                throw new InvalidArgumentException("Firebase credentials file not found: {$absolute}");
            }

            return $absolute;
        }

        throw new InvalidArgumentException('Firebase credentials are not configured.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json, string $sourceLabel): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$sourceLabel} is not valid JSON.");
        }

        return $decoded;
    }

    private static function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
