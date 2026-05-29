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
            $fail('The uploaded file must be a valid image.');

            return;
        }

        if ($value->getSize() > $this->maxKilobytes * 1024) {
            $fail("The image must be {$this->maxKilobytes} MB or smaller.");

            return;
        }

        if (! WorkoutImageValidator::isAllowed($value)) {
            $fail('The uploaded file must be a valid image (JPEG, PNG, GIF, WebP, HEIC, BMP, and other common formats).');
        }
    }
}
