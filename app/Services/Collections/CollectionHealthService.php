<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use App\Support\HealthBinWidth;
use App\Support\TimeWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads the per-minute ping counters written by CollectionPingRecorder and
 * aggregates them into a binned series plus a derived health summary.
 *
 * Minute rows are the base resolution; coarser bins are truncations computed in
 * SQL, so there is no rollup table to keep in step.
 */
class CollectionHealthService
{
    public const DEFAULT_WINDOW = '1h';

    /**
     * Turn the request's bin/window/from/to into a concrete list of bin starts.
     *
     * Two range modes, each taking the reading that is natural for how it is asked:
     *
     *  - window (default): the last N bins ending at the current, partial bin. So
     *    `?window=1h` with minute bins is 60 bins, not 61 - the bin containing
     *    `from` is excluded because the window is a duration, not a span.
     *  - from/to: every bin from the one containing `from` to the one containing
     *    `to`, both inclusive, because an explicit span means what it says.
     *
     * Throws ValidationException so callers can invoke this alongside
     * $request->validate() and get a 422 rather than a 500.
     *
     * @return array{bin: HealthBinWidth, bins: array<int, Carbon>, from: Carbon, to_exclusive: Carbon}
     *
     * @throws ValidationException
     */
    public function resolveWindow(array $params): array
    {
        $bin = HealthBinWidth::parse($params['bin'] ?? null);

        $to = isset($params['to']) ? Carbon::parse($params['to'])->utc() : Carbon::now();
        $last = $bin->floor($to);

        if (isset($params['from'])) {
            $from = Carbon::parse($params['from'])->utc();

            if ($from->greaterThan($to)) {
                throw ValidationException::withMessages([
                    'from' => 'The from field must not be later than to.',
                ]);
            }

            $first = $bin->floor($from);
        } else {
            $windowStart = TimeWindow::subtract($last, $params['window'] ?? self::DEFAULT_WINDOW);

            // First bin boundary strictly after the window start, so a whole-numbered
            // window yields exactly that many bins.
            $first = $bin->floor($windowStart);
            if ($first->lessThanOrEqualTo($windowStart)) {
                $first = $bin->next($first);
            }
        }

        if ($first->greaterThan($last)) {
            throw ValidationException::withMessages([
                'from' => 'The requested range contains no complete bins.',
            ]);
        }

        $maxBins = (int) config('system.collection_health_max_bins', 2000);
        $estimate = $bin->countBetween($first, $last);

        if ($estimate > $maxBins) {
            throw ValidationException::withMessages([
                'bin' => "The requested range needs about {$estimate} {$bin->label()} bins, which exceeds the maximum of {$maxBins}. Use a coarser bin or a shorter range.",
            ]);
        }

        $bins = [];
        for ($cursor = $first->copy(); $cursor->lessThanOrEqualTo($last); $cursor = $bin->next($cursor)) {
            $bins[] = $cursor->copy();
        }

        return [
            'bin' => $bin,
            'bins' => $bins,
            'from' => $first,
            'to_exclusive' => $bin->next($last),
        ];
    }

    /**
     * Binned ping series and summary for one collection, both task types.
     *
     * @param  array{bin: HealthBinWidth, bins: array<int, Carbon>, from: Carbon, to_exclusive: Carbon}  $window
     */
    public function health(int $collectionId, array $window): array
    {
        $rows = $this->aggregate($collectionId, $window['bin'], $window['from'], $window['to_exclusive']);

        $series = [];
        $summary = [];

        // Captured once so every bin in the response - and both task types - is
        // measured against the same instant.
        $now = Carbon::now();

        foreach (TaskType::cases() as $taskType) {
            [$series[$taskType->value], $summary[$taskType->value]] = $this->buildSeries(
                $rows[$taskType->value] ?? [],
                $window['bins'],
                $window['bin'],
                $now
            );
        }

        return [
            'collection_id' => $collectionId,
            'bin' => $window['bin']->label(),
            'from' => $window['from']->toIso8601ZuluString(),
            'to' => $window['to_exclusive']->toIso8601ZuluString(),
            'summary' => $summary,
            'series' => $series,
        ];
    }

    /**
     * One grouped query for both task types.
     *
     * COUNT(*) is minutes-with-pings, not pings: a bucket row only exists for a
     * minute that had at least one, so counting rows within the group gives the
     * silent-minute count for free at any bin width.
     *
     * @return array<string, array<string, object>> [task_type][bin start 'Y-m-d H:i:s'] => row
     */
    private function aggregate(int $collectionId, HealthBinWidth $bin, Carbon $from, Carbon $toExclusive): array
    {
        // The truncation comes from the resolved bin width, never from request input.
        $expression = $bin->sqlExpression();

        $rows = DB::select(
            "SELECT task_type, {$expression} AS bin, SUM(n) AS n, COUNT(*) AS minutes_with_pings, MAX(last_ping_at) AS last_ping_at
             FROM collection_ping_buckets
             WHERE collection_id = ?
               AND bucket_minute >= ?
               AND bucket_minute < ?
             GROUP BY task_type, bin
             ORDER BY bin",
            [
                $collectionId,
                $from->toDateTimeString(),
                $toExclusive->toDateTimeString(),
            ]
        );

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->task_type][$row->bin] = $row;
        }

        return $indexed;
    }

    /**
     * Zero-fill the series across every bin, normalise each bin to a per-minute
     * rate, then derive the summary from it.
     *
     * The zero-filling is the point: a gap is only visible if the empty bins are
     * present in the response.
     *
     * `per_minute` rather than the raw sum is what makes the series comparable
     * across bin widths - the same host at the same cadence reads the same at
     * `minute`, `10m` or `hour`, so changing the bin re-shapes the line without
     * re-scaling the axis.
     *
     * @param  array<string, object>  $rows
     * @param  array<int, Carbon>  $bins
     * @return array{0: array<int, array{bin: string, n: int, minutes: int, silent_minutes: int, per_minute: float|null}>, 1: array<string, mixed>}
     */
    private function buildSeries(array $rows, array $bins, HealthBinWidth $bin, Carbon $now): array
    {
        $series = [];
        $pings = 0;
        $minutes = 0;
        $silentMinutes = 0;
        $emptyBins = 0;
        $currentGap = 0;
        $longestGap = 0;
        $lastPingAt = null;

        foreach ($bins as $binStart) {
            $key = $binStart->toDateTimeString();
            $row = $rows[$key] ?? null;
            $n = (int) ($row->n ?? 0);

            $binMinutes = $this->coveredMinutes($binStart, $bin, $now);

            // A bucket row exists only for a minute that had a ping, so anything
            // else inside the covered part of the bin was silence. The max guards
            // clock skew landing a bucket in the not-yet-elapsed part of the bin.
            $binSilentMinutes = max(0, $binMinutes - (int) ($row->minutes_with_pings ?? 0));

            $series[] = [
                'bin' => $binStart->toIso8601ZuluString(),
                'n' => $n,
                'minutes' => $binMinutes,
                'silent_minutes' => $binSilentMinutes,
                'per_minute' => $binMinutes > 0 ? round($n / $binMinutes, 3) : null,
            ];

            $pings += $n;
            $minutes += $binMinutes;
            $silentMinutes += $binSilentMinutes;

            if ($n === 0) {
                $emptyBins++;
                $currentGap++;
                $longestGap = max($longestGap, $currentGap);
            } else {
                $currentGap = 0;
            }

            if ($row && $row->last_ping_at !== null) {
                $candidate = Carbon::parse($row->last_ping_at);
                if ($lastPingAt === null || $candidate->greaterThan($lastPingAt)) {
                    $lastPingAt = $candidate;
                }
            }
        }

        return [
            $series,
            [
                'last_ping_at' => $lastPingAt?->toIso8601ZuluString(),
                'pings' => $pings,
                // Minute-weighted over the range, not the mean of the bin rates, so
                // a half-finished final bin cannot skew it.
                'per_minute' => $minutes > 0 ? round($pings / $minutes, 3) : null,
                'minutes' => $minutes,
                'silent_minutes' => $silentMinutes,
                'bins' => count($bins),
                'empty_bins' => $emptyBins,
                'longest_gap_bins' => $longestGap,
            ],
        ];
    }

    /**
     * Minutes of this bin that have actually elapsed - the denominator for its
     * per-minute rate.
     *
     * The final bin of a range is always in progress, so dividing it by the
     * nominal width would drag a healthy host's last point toward zero and read
     * as an outage. Dividing by the elapsed part keeps the line flat.
     *
     * Rounded up with a floor of one because storage is minute-granular: a bin ten
     * seconds old has exactly one minute bucket in play, so `n / 1` is the honest
     * rate, there is no divide-by-zero, and no spike from a fractional divisor.
     */
    private function coveredMinutes(Carbon $binStart, HealthBinWidth $bin, Carbon $now): int
    {
        $nominal = $bin->minutesIn($binStart);

        if ($binStart->copy()->addMinutes($nominal)->lessThanOrEqualTo($now)) {
            return $nominal;
        }

        // Entirely in the future - only reachable via a from/to range past now.
        // No data can exist here, which is not the same fact as silence.
        if ($binStart->greaterThanOrEqualTo($now)) {
            return 0;
        }

        $elapsed = (int) ceil($binStart->diffInSeconds($now) / 60);

        return max(1, min($nominal, $elapsed));
    }
}
