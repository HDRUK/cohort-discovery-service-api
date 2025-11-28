<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IdOrUuid implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isIntegerId = ctype_digit($value);
        $isUuid = (bool) preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $value
        );

        if (! $isIntegerId && ! $isUuid) {
            $fail("The {$attribute} must be a valid integer ID or UUID.");
        }
    }
}
