<?php

namespace App\Console\Commands;

use App\Contracts\ApiCommand;
use App\Jobs\PruneCollectionPingBucketsJob;
use Carbon\Carbon;
use Log;

class PruneCollectionPingBuckets implements ApiCommand
{
    private string $tag = 'PruneCollectionPingBuckets';

    public function rules(): array
    {
        return [];
    }

    public function handle(array $validated): mixed
    {
        Log::info($this->tag . ' starting: ' . Carbon::now()->toDateTimeString());

        PruneCollectionPingBucketsJob::dispatch();

        Log::info($this->tag . ' spawned PruneCollectionPingBucketsJob: ' . Carbon::now()->toDateTimeString());
        Log::info($this->tag . ' finished: ' . Carbon::now()->toDateTimeString());

        return null;
    }
}
