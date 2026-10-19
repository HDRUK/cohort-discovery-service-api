<?php

namespace App\Services\Collections;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\CollectionActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollectionPollRecorder
{
    public function record(Collection $collection, TaskType $taskType, ?Carbon $at = null): void
    {
        $log = CollectionActivityLog::firstOrCreate([
            'collection_id' => $collection->id,
            'task_type' => $taskType->value,
        ]);

        if (! $log->wasRecentlyCreated) {
            $log->touch();
        }

        $this->recordPing($collection->id, $taskType, $at ? $at->copy() : Carbon::now());
    }

    private function recordPing(int $collectionId, TaskType $taskType, Carbon $at): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getConfig('use_upsert_alias')) {
                $insertRowAlias = 'AS new';
                $lastPingAtUpdate = 'new.last_ping_at';
            } else {
                $insertRowAlias = '';
                $lastPingAtUpdate = 'VALUES(last_ping_at)';
            }

            $connection->statement(
                "INSERT INTO collection_ping_buckets
                    (collection_id, task_type, bucket_minute, n, first_ping_at, last_ping_at)
                 VALUES (?, ?, ?, 1, ?, ?) {$insertRowAlias}
                 ON DUPLICATE KEY UPDATE
                    n = n + 1,
                    last_ping_at = {$lastPingAtUpdate}",
                [
                    $collectionId,
                    $taskType->value,
                    $at->copy()->startOfMinute()->toDateTimeString(),
                    $at->toDateTimeString(),
                    $at->toDateTimeString(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('CollectionPollRecorder@recordPing - failed to record ping', [
                'collection_id' => $collectionId,
                'task_type' => $taskType->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
