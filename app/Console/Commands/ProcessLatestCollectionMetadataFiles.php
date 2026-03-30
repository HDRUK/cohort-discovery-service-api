<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMetadataFile;
use App\Models\Collection;
use App\Models\ResultFile;
use Illuminate\Console\Command;

class ProcessLatestCollectionMetadataFiles extends Command
{
    protected $signature = 'collections:process-latest-metadata 
                            {--sync : Run jobs synchronously instead of queueing}
                            {--only= : Process a single collection id}';

    protected $description = 'For each collection, find the latest metadata.bcos result file and process it';

    public function handle(): int
    {
        $onlyCollectionId = $this->option('only');
        $sync = (bool) $this->option('sync');

        $query = Collection::query();

        if ($onlyCollectionId) {
            $query->where('id', $onlyCollectionId);
        }

        $collections = $query->get();

        if ($collections->isEmpty()) {
            $this->warn('No collections found.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;

        foreach ($collections as $collection) {
            $resultFile = ResultFile::query()
                ->where('collection_id', $collection->id)
                ->where('file_name', 'like', '%metadata.bcos')
                ->latest('updated_at')
                ->latest('id')
                ->first();

            if (! $resultFile) {
                $this->line("Skipping collection {$collection->id}: no metadata.bcos file found");
                $skipped++;
                continue;
            }

            $this->info(
                "Collection {$collection->id}: processing ResultFile {$resultFile->id} ({$resultFile->file_name})"
            );

            if ($sync) {
                ProcessMetadataFile::dispatchSync($resultFile->id);
            } else {
                ProcessMetadataFile::dispatch($resultFile->id)->afterCommit();
            }

            $processed++;
        }

        $this->newLine();
        $this->info("Done. Processed: {$processed}. Skipped: {$skipped}.");

        return self::SUCCESS;
    }
}
