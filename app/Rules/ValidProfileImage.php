<?php

namespace App\Rules;

use App\Support\WorkoutImageValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class ValidProfileImage implements ValidationRule
{
    public function __construct(private int $maxKilobytes = 10240) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Please select an image to upload.');

            return;
        }

        if (! $value->isValid()) {
            $fail($this->uploadErrorMessage($value) ?? 'The image failed to upload. Try a smaller photo or a different format.');

            return;
        }

        if ($value->getSize() > $this->maxKilobytes * 1024) {
            $maxMb = (int) round($this->maxKilobytes / 1024);
            $fail("The image must be {$maxMb} MB or smaller.");

            return;
        }

        if (! WorkoutImageValidator::isAllowed($value)) {
            $fail('The uploaded file must be a valid image (JPEG, PNG, GIF, WebP, HEIC, BMP, and other common formats).');
        }
    }

    private function uploadErrorMessage(UploadedFile $file): ?string
    {
        $maxMb = (int) round($this->maxKilobytes / 1024);

        return match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The image is too large for the server to accept. Please use a photo under {$maxMb} MB.",
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select an image to upload.',
            default => null,
        };
    }
}
