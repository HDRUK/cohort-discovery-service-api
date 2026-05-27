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
        $collectionId = $resultFile->collection_id;

        $hasDeathCategory = Distribution::where('collection_id', $collectionId)
            ->where('category', 'Death')
            ->exists();

        $deathFilter = $hasDeathCategory ? 1 : 0;

        $supportsDeathFilter = $hasDeathCategory || Distribution::where('collection_id', $collectionId)
            ->where('concept_id', 4306655)
            ->exists() ? 1 : 0;

        $supportsLocationFilter = Distribution::where('collection_id', $collectionId)
            ->where('category', 'Location')
            ->exists() ? 1 : 0;

        CollectionMetadata::where('result_file_id', $this->metadataResultFileId)
            ->update([
                'death_filter'             => $deathFilter,
                'supports_death_filter'    => $supportsDeathFilter,
                'supports_location_filter' => $supportsLocationFilter,
            ]);

        Log::info("[{$this->tag}] synced", [
            'metadata_result_file_id'  => $this->metadataResultFileId,
            'collection_id'            => $collectionId,
            'death_filter'             => $deathFilter,
            'supports_death_filter'    => $supportsDeathFilter,
            'supports_location_filter' => $supportsLocationFilter,
        ]);
    }
}
