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

        return @getimagesize($file->getPathname()) !== false;
    }

    public static function maxKilobytes(): int
    {
        return 10240;
    }
}
