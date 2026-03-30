<?php

namespace App\Services\Collections;

use App\Jobs\ProcessMetadataFile;
use App\Models\Collection;

class ProcessLatestCollectionMetadataService
{
    public function handle(array $collectionIds = []): array
    {
        $collections = Collection::query()
            ->when(
                ! empty($collectionIds),
                fn ($query) => $query->whereIn('id', $collectionIds)
            )
            ->with([
                'latestMetadataResultFile' => fn ($query) => $query->select(
                    'result_files.id',
                    'result_files.collection_id',
                    'result_files.file_name',
                ),
            ])
            ->get(['id']);

        if ($collections->isEmpty()) {
            return [
                'total_collections' => 0,
                'processed' => 0,
                'skipped' => 0,
                'processed_items' => [],
                'skipped_collection_ids' => [],
                'mode' => 'queued',
            ];
        }

        $processed = 0;
        $skipped = 0;
        $processedItems = [];
        $skippedCollectionIds = [];

        foreach ($collections as $collection) {
            $resultFile = $collection->latestMetadataResultFile;

            if (! $resultFile) {
                $skipped++;
                $skippedCollectionIds[] = $collection->id;
                continue;
            }

            ProcessMetadataFile::dispatch($resultFile->id)->afterCommit();

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
            'mode' =>  'queued',
        ];
    }
}
