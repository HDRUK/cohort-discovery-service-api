<?php

namespace App\Jobs;

use App\Models\CollectionMetadata;
use App\Models\Distribution;
use App\Models\ResultFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncCollectionCapabilities implements ShouldQueue
{
    use Queueable;

    private string $tag = 'SyncCollectionCapabilities';

    public function __construct(public int $metadataResultFileId)
    {
    }

    public function handle(): void
    {
        $resultFile = ResultFile::findOrFail($this->metadataResultFileId);
        $collectionId = $resultFile->collection_id;

        $categories = Distribution::where('collection_id', $collectionId)
            ->distinct()
            ->pluck('category')
            ->all();

        $has = fn (string $cat) => in_array($cat, $categories) ? 1 : 0;

        $hasDeathCategory = $has('Death');
        $supportsDeathFilter = $hasDeathCategory || Distribution::where('collection_id', $collectionId)
            ->where('concept_id', 4306655)
            ->exists() ? 1 : 0;

        $updates = [
            'death_filter'             => $hasDeathCategory,
            'supports_death_filter'    => $supportsDeathFilter,
            'supports_location_filter' => $has('Location'),
            'supports_condition'       => $has('Condition'),
            'supports_drug'            => $has('Drug'),
            'supports_observation'     => $has('Observation'),
            'supports_measurement'     => $has('Measurement'),
            'supports_demographics'    => $has('DEMOGRAPHICS'),
            // location_has_coordinates intentionally omitted — requires bunny to report
        ];

        CollectionMetadata::where('result_file_id', $this->metadataResultFileId)
            ->update($updates);

        Log::info("[{$this->tag}] synced", array_merge(
            ['metadata_result_file_id' => $this->metadataResultFileId, 'collection_id' => $collectionId],
            $updates
        ));
    }
}
