<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Services\VocabularyDrift\VocabularyDriftService;
use Illuminate\Console\Command;

class VocabDriftReport extends Command
{
    /**
     * Example:
     *   php artisan collections:vocab-drift
     *   php artisan collections:vocab-drift --collection-pid=col_abc123 --details
     */
    protected $signature = 'collections:vocab-drift
                            {--collection-pid= : Restrict the report to a single collection (public pid)}
                            {--details : Also list the mismatched concepts per collection}';

    protected $description = 'Report per-collection vocabulary drift (concepts whose reported domain disagrees with the central OMOP domain).';

    public function handle(VocabularyDriftService $driftService): int
    {
        $pid = $this->option('collection-pid');
        $collectionIds = null;

        if ($pid !== null) {
            $collection = Collection::where('pid', $pid)->first();
            if (!$collection) {
                $this->error("Collection with pid [{$pid}] not found.");

                return self::FAILURE;
            }

            $collectionIds = [$collection->id];
        }

        $summary = $driftService->summary($collectionIds)
            ->sortByDesc('mismatch_rate')
            ->values();

        if ($summary->isEmpty()) {
            $this->info('No distribution data found for the requested collection(s).');

            return self::SUCCESS;
        }

        $collections = Collection::whereIn('id', $summary->pluck('collection_id'))
            ->get(['id', 'pid', 'name'])
            ->keyBy('id');

        $this->table(
            ['Collection', 'Pid', 'Name', 'Total', 'Mismatched', 'Rate'],
            $summary->map(fn ($row) => [
                $row['collection_id'],
                $collections[$row['collection_id']]->pid ?? '—',
                $collections[$row['collection_id']]->name ?? '—',
                $row['total_concepts'],
                $row['mismatched_concepts'],
                number_format($row['mismatch_rate'] * 100, 1).'%',
            ])->all()
        );

        if ($this->option('details')) {
            foreach ($summary as $row) {
                $mismatches = $driftService->details($row['collection_id']);
                if ($mismatches->isEmpty()) {
                    continue;
                }

                $collectionPid = $collections[$row['collection_id']]->pid ?? $row['collection_id'];

                $this->newLine();
                $this->line("Collection {$collectionPid} — mismatched concepts:");
                $this->table(
                    ['Concept', 'Name', 'Reported', 'Central'],
                    $mismatches->map(fn ($m) => [
                        $m['concept_id'],
                        $m['concept_name'],
                        $m['reported_domain'],
                        $m['central_domain'],
                    ])->all()
                );
            }
        }

        return self::SUCCESS;
    }
}
