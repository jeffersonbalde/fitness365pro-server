<?php

namespace App\Rules;

use App\Support\WorkoutImageValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

final class ValidWorkoutImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Each workout photo must be a valid image file.');

            return;
        }

        if ($value->getSize() > WorkoutImageValidator::maxKilobytes() * 1024) {
            $fail('Each workout photo must be '.WorkoutImageValidator::maxKilobytes().' MB or smaller.');

            return;
        }

        if (! WorkoutImageValidator::isAllowed($value)) {
            $fail('Each workout photo must be a valid image file (JPEG, PNG, GIF, WebP, HEIC, and other common formats).');
        }
    }
}
