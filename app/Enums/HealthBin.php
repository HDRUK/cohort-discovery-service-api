<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;

enum HealthBin: string
{
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public static function fromSuffix(string $suffix): self
    {
        return match ($suffix) {
            'm' => self::Minute,
            'h' => self::Hour,
            'd' => self::Day,
            'w' => self::Week,
            default => throw new \ValueError("\"{$suffix}\" is not a valid bin suffix - use m, h, d or w."),
        };
    }

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

    public function floor(Carbon $moment): Carbon
    {
        return match ($this) {
            self::Minute => $moment->copy()->startOfMinute(),
            self::Hour => $moment->copy()->startOfHour(),
            self::Day => $moment->copy()->startOfDay(),
            self::Week => $moment->copy()->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $moment->copy()->startOfMonth(),
        };
    }

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

    public function subtract(Carbon $moment, int $amount): Carbon
    {
        return match ($this) {
            self::Minute => $moment->copy()->subMinutes($amount),
            self::Hour => $moment->copy()->subHours($amount),
            self::Day => $moment->copy()->subDays($amount),
            self::Week => $moment->copy()->subWeeks($amount),
            self::Month => $moment->copy()->subMonths($amount),
        };
    }

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
