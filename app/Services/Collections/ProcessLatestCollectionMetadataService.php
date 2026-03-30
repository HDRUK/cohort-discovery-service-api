<?php

namespace App\Services\Collections;

use App\Jobs\ProcessMetadataFile;
use App\Models\Collection;
use App\Models\ResultFile;

class ProcessLatestCollectionMetadataService
{
    public function handle(array $collectionIds = [], bool $sync = false): array
    {
        $query = Collection::query();

        if (! empty($collectionIds)) {
            $query->whereIn('id', $collectionIds);
        }

        $collections = $query->get();

        if ($collections->isEmpty()) {
            return [
                'total_collections' => 0,
                'processed' => 0,
                'skipped' => 0,
                'processed_items' => [],
                'skipped_collection_ids' => [],
            ];
        }

        $processed = 0;
        $skipped = 0;
        $processedItems = [];
        $skippedCollectionIds = [];

        foreach ($collections as $collection) {
            $resultFile = ResultFile::query()
                ->where('collection_id', $collection->id)
                ->where('file_name', 'like', '%metadata.bcos')
                ->latest('updated_at')
                ->latest('id')
                ->first();

            if (! $resultFile) {
                $skipped++;
                $skippedCollectionIds[] = $collection->id;
                continue;
            }

            if ($sync) {
                ProcessMetadataFile::dispatchSync($resultFile->id);
            } else {
                ProcessMetadataFile::dispatch($resultFile->id)->afterCommit();
            }

            $processed++;

            $processedItems[] = [
                'collection_id' => $collection->id,
                'result_file_id' => $resultFile->id,
                'file_name' => $resultFile->file_name,
            ];
        }

        return [
            'total_collections' => $collections->count(),
            'processed' => $processed,
            'skipped' => $skipped,
            'processed_items' => $processedItems,
            'skipped_collection_ids' => $skippedCollectionIds,
            'mode' => $sync ? 'sync' : 'queued',
        ];
    }
}
