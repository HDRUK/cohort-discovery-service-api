<?php

namespace App\Support;

use App\Enums\HealthBin;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class HealthBinWidth
{
    private const EPOCH = '1970-01-01 00:00:00';

    private const EPOCH_MONDAY = '1969-12-29 00:00:00';

    private function __construct(
        private readonly HealthBin $unit,
        private readonly int $every,
        private readonly string $label,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public static function parse(?string $value): self
    {
        if ($value === null || $value === '') {
            $value = HealthBin::Minute->value;
        }

        if ($unit = HealthBin::tryFrom($value)) {
            return new self($unit, 1, $unit->value);
        }

        if (! preg_match('/^([1-9]\d{0,4})([mhdw])$/', $value, $matches)) {
            throw ValidationException::withMessages([
                'bin' => 'The bin field must be minute, hour, day, week, month, or a positive multiple of m, h, d or w - for example 10m, 6h, 2d or 4w.',
            ]);
        }

        $unit = match ($matches[2]) {
            'm' => HealthBin::Minute,
            'h' => HealthBin::Hour,
            'd' => HealthBin::Day,
            'w' => HealthBin::Week,
        };

        $every = (int) $matches[1];

        return new self($unit, $every, $every === 1 ? $unit->value : $value);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sqlExpression(): string
    {
        if ($this->every === 1) {
            return $this->unit->sqlExpression();
        }

        $step = $this->stepMinutes();
        $anchor = $this->anchor();

        return "DATE_ADD('{$anchor}', INTERVAL FLOOR(TIMESTAMPDIFF(MINUTE, '{$anchor}', bucket_minute) / {$step}) * {$step} MINUTE)";
    }

    public function floor(Carbon $moment): Carbon
    {
        if ($this->every === 1) {
            return $this->unit->floor($moment);
        }

        $anchor = Carbon::parse($this->anchor(), 'UTC');
        $step = $this->stepMinutes() * 60;
        $elapsed = $moment->getTimestamp() - $anchor->getTimestamp();

        return $anchor->addSeconds(intdiv($elapsed, $step) * $step);
    }

    public function next(Carbon $binStart): Carbon
    {
        return $this->every === 1
            ? $this->unit->next($binStart)
            : $binStart->copy()->addMinutes($this->stepMinutes());
    }

    public function minutesIn(Carbon $binStart): int
    {
        return (int) $binStart->diffInMinutes($this->next($binStart));
    }

    public function countBetween(Carbon $first, Carbon $last): int
    {
        if ($this->every === 1) {
            return $this->unit->countBetween($first, $last);
        }

        return intdiv((int) abs($first->diffInMinutes($last)), $this->stepMinutes()) + 1;
    }

    private function stepMinutes(): int
    {
        return $this->every * match ($this->unit) {
            HealthBin::Minute => 1,
            HealthBin::Hour => 60,
            HealthBin::Day => 1440,
            HealthBin::Week => 10080,
            HealthBin::Month => throw new \LogicException('Month bins have no fixed width in minutes, so they have no multiple form.'),
        };
    }

    private function anchor(): string
    {
        return $this->unit === HealthBin::Week ? self::EPOCH_MONDAY : self::EPOCH;
    }
}
