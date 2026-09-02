<?php

namespace App\Support;

use App\Enums\HealthBin;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A resolved bin width for the collection health endpoint: a HealthBin unit plus
 * how many of them make one bin.
 *
 * The named units (`minute`, `hour`, `day`, `week`, `month`) are multiples of one
 * and delegate straight to the enum, keeping their calendar-aware boundaries -
 * Monday-start weeks, 1st-of-the-month months. Anything wider, such as `10m` or
 * `6h`, is a fixed number of minutes and is snapped to a grid anchored at the
 * epoch, so bin starts are absolute rather than relative to the request time: a
 * `10m` bin always starts at :00, :10, :20 and so on, and two overlapping
 * requests return the same bin boundaries.
 *
 * As with the enum, the SQL truncation and the PHP floor must agree exactly or
 * rows land in a bin the zero-fill never generated and the series reads as all
 * zeros - hence both live here, next to each other.
 */
final class HealthBinWidth
{
    /** Grid origin for minute, hour and day multiples. */
    private const EPOCH = '1970-01-01 00:00:00';

    /**
     * Grid origin for week multiples - the Monday before the epoch, so multi-week
     * bins keep the Monday-start convention of HealthBin::Week.
     */
    private const EPOCH_MONDAY = '1969-12-29 00:00:00';

    private function __construct(
        private readonly HealthBin $unit,
        private readonly int $every,
        private readonly string $label,
    ) {
    }

    /**
     * Parse the request's `bin` parameter: either a named unit, or a positive
     * multiple of minutes, hours, days or weeks - `10m`, `6h`, `2d`, `4w`.
     *
     * Months have no multiple form; they are the one variable-width unit, so
     * `3mo` would not be a fixed number of minutes.
     *
     * Throws ValidationException rather than returning null so callers get a 422
     * under `errors.bin`, the same as the enum rule it replaced.
     *
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

        // Bounded digits: the width only has to stay inside the retention window,
        // and a small cap keeps the minute arithmetic well away from overflow.
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

        // `1h` and `hour` are the same bin, so normalise the echoed label.
        return new self($unit, $every, $every === 1 ? $unit->value : $value);
    }

    /**
     * How the resolved width is echoed back in the response.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * SQL truncation of `bucket_minute` down to this bin's start.
     *
     * The multiple form is calendar arithmetic on the stored value via
     * TIMESTAMPDIFF, not UNIX_TIMESTAMP, so it does not depend on the connection
     * time zone. Both interpolated values are derived here, never from input.
     */
    public function sqlExpression(): string
    {
        if ($this->every === 1) {
            return $this->unit->sqlExpression();
        }

        $step = $this->stepMinutes();
        $anchor = $this->anchor();

        return "DATE_ADD('{$anchor}', INTERVAL FLOOR(TIMESTAMPDIFF(MINUTE, '{$anchor}', bucket_minute) / {$step}) * {$step} MINUTE)";
    }

    /**
     * Start of the bin containing $moment. Must agree with sqlExpression().
     */
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

    /**
     * Start of the bin after the one beginning at $binStart.
     */
    public function next(Carbon $binStart): Carbon
    {
        return $this->every === 1
            ? $this->unit->next($binStart)
            : $binStart->copy()->addMinutes($this->stepMinutes());
    }

    /**
     * Nominal length of the bin starting at $binStart, in whole minutes.
     *
     * Derived from next() rather than from stepMinutes() so the variable-width
     * units come out right without a special case - a month is 28 to 31 days
     * depending on which one it is. APP_TIMEZONE is UTC, so there is no DST
     * discontinuity to account for.
     */
    public function minutesIn(Carbon $binStart): int
    {
        return (int) $binStart->diffInMinutes($this->next($binStart));
    }

    /**
     * Number of bins spanned by $first..$last inclusive. Only feeds the size
     * guardrail, so the enum's approximation for variable-width units is fine.
     */
    public function countBetween(Carbon $first, Carbon $last): int
    {
        if ($this->every === 1) {
            return $this->unit->countBetween($first, $last);
        }

        return intdiv((int) abs($first->diffInMinutes($last)), $this->stepMinutes()) + 1;
    }

    /**
     * Width of one bin in minutes. Never reached for months, which parse() only
     * ever resolves as a multiple of one.
     */
    private function stepMinutes(): int
    {
        return $this->every * match ($this->unit) {
            HealthBin::Minute => 1,
            HealthBin::Hour => 60,
            HealthBin::Day => 1440,
            HealthBin::Week => 10080,
            default => throw new \LogicException("HealthBin::{$this->unit->name} has no fixed width in minutes."),
        };
    }

    private function anchor(): string
    {
        return $this->unit === HealthBin::Week ? self::EPOCH_MONDAY : self::EPOCH;
    }
}
