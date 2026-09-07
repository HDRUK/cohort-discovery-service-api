<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use App\Support\HealthBinWidth;
use App\Support\TimeWindow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CollectionHealthService
{
    public const DEFAULT_WINDOW = '1h';

    /**
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

        $maxBins = (int) config('system.collection_health_max_bins');
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
     * @param  array{bin: HealthBinWidth, bins: array<int, Carbon>, from: Carbon, to_exclusive: Carbon}  $window
     */
    public function health(int $collectionId, array $window): array
    {
        $rows = $this->aggregate($collectionId, $window['bin'], $window['from'], $window['to_exclusive']);

        $series = [];
        $summary = [];

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
     * @return array<string, array<string, object>>
     */
    private function aggregate(int $collectionId, HealthBinWidth $bin, Carbon $from, Carbon $toExclusive): array
    {
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
                'per_minute' => $minutes > 0 ? round($pings / $minutes, 3) : null,
                'minutes' => $minutes,
                'silent_minutes' => $silentMinutes,
                'bins' => count($bins),
                'empty_bins' => $emptyBins,
                'longest_gap_bins' => $longestGap,
            ],
        ];
    }

    private function coveredMinutes(Carbon $binStart, HealthBinWidth $bin, Carbon $now): int
    {
        $nominal = $bin->minutesIn($binStart);

        if ($binStart->copy()->addMinutes($nominal)->lessThanOrEqualTo($now)) {
            return $nominal;
        }

        if ($binStart->greaterThanOrEqualTo($now)) {
            return 0;
        }

        $elapsed = (int) ceil($binStart->diffInSeconds($now) / 60);

        return max(1, min($nominal, $elapsed));
    }
}
