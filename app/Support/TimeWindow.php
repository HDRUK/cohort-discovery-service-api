<?php

namespace App\Support;

use App\Enums\HealthBin;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class TimeWindow
{
    /**
     * @throws ValidationException
     */
    public static function subtract(Carbon $moment, string $window): Carbon
    {
        if (! preg_match('/^([1-9]\d*)([mhdw])$/', $window, $matches)) {
            throw ValidationException::withMessages([
                'window' => 'The window field must be a positive number followed by m, h, d or w - for example 30m, 24h, 7d or 2w.',
            ]);
        }

        return HealthBin::fromSuffix($matches[2])->subtract($moment, (int) $matches[1]);
    }
}
