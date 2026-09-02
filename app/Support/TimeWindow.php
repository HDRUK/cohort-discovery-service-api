<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Parsing for the `window` query parameter shared by the collection telemetry
 * endpoints - a duration back from a given moment, written as a positive integer
 * followed by a unit.
 *
 * Lives here rather than on either service so the endpoints cannot drift apart on
 * what a window is, or on the message they reject a bad one with.
 */
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

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'm' => $moment->copy()->subMinutes($amount),
            'h' => $moment->copy()->subHours($amount),
            'd' => $moment->copy()->subDays($amount),
            default => $moment->copy()->subWeeks($amount),
        };
    }
}
