<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Retention sweep for the per-minute ping counters.
 *
 * The table grows at roughly 2,880 rows per day per collection, so it needs a
 * bound. Deletes run in capped batches rather than as one statement, to keep the
 * transaction and any replication lag short.
 */
class PruneCollectionPingBucketsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const BATCH_SIZE = 10000;

    public function handle(): void
    {
        $retentionDays = (int) config('system.collection_ping_retention_days', 30);
        $cutoff = Carbon::now()->subDays($retentionDays)->startOfMinute();

        $deleted = 0;

        do {
            $batch = DB::table('collection_ping_buckets')
                ->where('bucket_minute', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->delete();

            $deleted += $batch;
        } while ($batch === self::BATCH_SIZE);

        Log::info('PruneCollectionPingBucketsJob - pruned ping buckets', [
            'retention_days' => $retentionDays,
            'cutoff' => $cutoff->toDateTimeString(),
            'deleted' => $deleted,
        ]);
    }
}
