<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Bin granularity for the collection health endpoint.
 *
 * Ping counters are stored per minute; every coarser bin is a truncation of that,
 * computed in SQL. Each case owns its truncation, its step and its floor so the
 * three stay in step with each other.
 */
enum HealthBin: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * SQL truncation of `bucket_minute` down to this bin's start.
     *
     * `Week` uses a Monday-start date rather than YEARWEEK() so the bin label is a
     * real timestamp the caller can chart, not an opaque "202635".
     */
    public function sqlExpression(): string
    {
        return match ($this) {
            self::Minute => "DATE_FORMAT(bucket_minute, '%Y-%m-%d %H:%i:00')",
            self::Hour => "DATE_FORMAT(bucket_minute, '%Y-%m-%d %H:00:00')",
            self::Day => "DATE_FORMAT(bucket_minute, '%Y-%m-%d 00:00:00')",
            self::Week => "DATE_FORMAT(DATE_SUB(bucket_minute, INTERVAL WEEKDAY(bucket_minute) DAY), '%Y-%m-%d 00:00:00')",
            self::Month => "DATE_FORMAT(bucket_minute, '%Y-%m-01 00:00:00')",
        };
    }

    /**
     * Start of the bin containing $moment. Must agree with sqlExpression().
     */
    public function floor(Carbon $moment): Carbon
    {
        return match ($this) {
            self::Minute => $moment->copy()->startOfMinute(),
            self::Hour => $moment->copy()->startOfHour(),
            self::Day => $moment->copy()->startOfDay(),
            // Monday-start, matching MySQL's WEEKDAY() in sqlExpression().
            self::Week => $moment->copy()->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $moment->copy()->startOfMonth(),
        };
    }

    /**
     * Start of the bin after the one beginning at $binStart.
     */
    public function next(Carbon $binStart): Carbon
    {
        return match ($this) {
            self::Minute => $binStart->copy()->addMinute(),
            self::Hour => $binStart->copy()->addHour(),
            self::Day => $binStart->copy()->addDay(),
            self::Week => $binStart->copy()->addWeek(),
            self::Month => $binStart->copy()->addMonth(),
        };
    }

    /**
     * Number of bins spanned by $first..$last inclusive. Approximate for the
     * variable-width cases, which is fine - it only feeds the size guardrail.
     */
    public function countBetween(Carbon $first, Carbon $last): int
    {
        $diff = match ($this) {
            self::Minute => $first->diffInMinutes($last),
            self::Hour => $first->diffInHours($last),
            self::Day => $first->diffInDays($last),
            self::Week => $first->diffInWeeks($last),
            self::Month => $first->diffInMonths($last),
        };

        return (int) abs($diff) + 1;
    }
}
