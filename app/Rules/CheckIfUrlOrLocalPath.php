<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CheckIfUrlOrLocalPath implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if url and valid, or is a valid local file path
        if (str_starts_with($value, 'http')) {
            if (!Str::isUrl($value)) {
                $fail('Must be a valid url');
            }
        } else if (!file_exists($value)) {
            $fail('Must be a valid file path, unable to locate file at the provided path.');
        }
    }
}
