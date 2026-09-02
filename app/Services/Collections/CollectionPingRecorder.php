<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records a collection host poll into the per-minute ping counters.
 *
 * Called from the polling endpoint, so this is a hot path: one write, no read.
 */
class CollectionPingRecorder
{
    /**
     * Increment the (collection, task_type, minute) counter for a single ping.
     *
     * Failures are swallowed and logged - collection hosts depend on the polling
     * endpoint, and losing a ping count must never cost them a poll.
     */
    public function record(int $collectionId, TaskType $taskType, ?Carbon $at = null): void
    {
        $at = $at ? $at->copy() : Carbon::now();
        $minute = $at->copy()->startOfMinute();

        try {
            // Raw SQL because Laravel's upsert() cannot express `n = n + 1`. The
            // unique index on (collection_id, task_type, bucket_minute) makes this
            // atomic, so concurrent polls cannot lose an increment.
            //
            // VALUES() is deprecated in MySQL 8.0.20+ in favour of the `AS new` row
            // alias, but the alias needs 8.0.19+ and VALUES() still works across all
            // of 8.0 - swap it when the minimum server version is pinned higher.
            DB::statement(
                'INSERT INTO collection_ping_buckets
                    (collection_id, task_type, bucket_minute, n, first_ping_at, last_ping_at)
                 VALUES (?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    n = n + 1,
                    last_ping_at = VALUES(last_ping_at)',
                [
                    $collectionId,
                    $taskType->value,
                    $minute->toDateTimeString(),
                    $at->toDateTimeString(),
                    $at->toDateTimeString(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('CollectionPingRecorder@record - failed to record ping', [
                'collection_id' => $collectionId,
                'task_type' => $taskType->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
