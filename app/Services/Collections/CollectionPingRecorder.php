<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollectionPingRecorder
{
    public function record(int $collectionId, TaskType $taskType, ?Carbon $at = null): void
    {
        $at = $at ? $at->copy() : Carbon::now();
        $minute = $at->copy()->startOfMinute();

        try {
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
