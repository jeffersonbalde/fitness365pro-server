<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

final class WorkoutImageValidator
{
    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'svgz',
        'heic', 'heif', 'avif', 'tif', 'tiff', 'ico', 'jfif', 'pjpeg', 'pjp',
    ];

    public static function isAllowed(?UploadedFile $file): bool
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return false;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension !== '' && in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return true;
        }

        $mime = strtolower((string) $file->getClientMimeType());
        if ($mime !== '' && str_starts_with($mime, 'image/')) {
            return true;
        }

        if (
            $mime === 'application/octet-stream'
            && $extension !== ''
            && in_array($extension, self::ALLOWED_EXTENSIONS, true)
        ) {
            return true;
        }

        $detectedMime = strtolower((string) @mime_content_type($file->getPathname()));
        if ($detectedMime !== '' && str_starts_with($detectedMime, 'image/')) {
            return true;
        }

        if (@getimagesize($file->getPathname()) !== false) {
            return true;
        }

        return self::hasImageMagicBytes($file);
    }

    public static function maxKilobytes(): int
    {
        return 10240;
    }

    private static function hasImageMagicBytes(UploadedFile $file): bool
    {
        $handle = @fopen($file->getPathname(), 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = fread($handle, 32);
        fclose($handle);

        if (! is_string($bytes) || strlen($bytes) < 4) {
            return false;
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return true;
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return true;
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return true;
        }

        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        if (str_starts_with($bytes, 'BM')) {
            return true;
        }

        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);

            return in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'avif', 'avis'], true);
        }

        return false;
    }
}
