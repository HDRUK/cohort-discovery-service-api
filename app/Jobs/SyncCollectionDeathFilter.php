<?php

namespace App\Jobs;

use App\Models\CollectionMetadata;
use App\Models\Distribution;
use App\Models\ResultFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCollectionDeathFilter implements ShouldQueue
{
    use Queueable;

    private string $tag = 'SyncCollectionDeathFilter';

    public function __construct(public int $metadataResultFileId) {}

    public function handle(): void
    {
        $resultFile = ResultFile::findOrFail($this->metadataResultFileId);

        $hasDeathCategory = Distribution::where('collection_id', $resultFile->collection_id)
            ->where('category', 'Death')
            ->exists();

        $deathFilter = $hasDeathCategory ? 1 : 0;

        CollectionMetadata::where('result_file_id', $this->metadataResultFileId)
            ->update(['death_filter' => $deathFilter]);

        Log::info("[{$this->tag}] synced", [
            'metadata_result_file_id' => $this->metadataResultFileId,
            'collection_id'           => $resultFile->collection_id,
            'death_filter'            => $deathFilter,
        ]);
    }
}
