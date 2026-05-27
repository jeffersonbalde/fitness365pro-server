<?php

namespace App\Support;

use InvalidArgumentException;

class FirebaseCredentials
{
    /**
     * Resolve Firebase service account credentials from environment.
     *
     * @return array<string, mixed>|non-empty-string
     */
    public static function resolve(): array|string
    {
        $json = env('FIREBASE_CREDENTIALS_JSON');
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException('FIREBASE_CREDENTIALS_JSON is not valid JSON.');
            }

            return $decoded;
        }

        $path = env('FIREBASE_CREDENTIALS');
        if (is_string($path) && trim($path) !== '') {
            $absolute = self::absolutePath($path);
            if (! is_file($absolute)) {
                throw new InvalidArgumentException("Firebase credentials file not found: {$absolute}");
            }

            return $absolute;
        }

        throw new InvalidArgumentException('Firebase credentials are not configured.');
    }

    private static function absolutePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
