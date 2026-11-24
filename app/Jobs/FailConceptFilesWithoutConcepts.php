<?php

namespace App\Jobs;

use App\Models\ResultFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class FailConceptFilesWithoutConcepts implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK_SIZE = 100;
    private const NO_CONCEPTS_ERROR = 'No concepts found in file';

    public $timeout = 300;
    public $tries = 1;

    public function __construct(private int $afterId = 0)
    {
    }

    public function handle(): void
    {
        $ids = ResultFile::query()
            ->where('file_name', 'code.distribution')
            ->where('status', ResultFile::STATUS_DONE)
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::CHUNK_SIZE)
            ->pluck('id');

        if ($ids->isEmpty()) {
            RefreshDistributionConceptsView::dispatch();
            RefreshLatestDistributionsView::dispatch();

            return;
        }

        DB::table('result_files')
            ->whereIn('id', $ids)
            ->whereNotExists(
                fn ($distributions) => $distributions
                    ->selectRaw('1')
                    ->from('distributions')
                    ->where('distributions.concept_id', '>', 0)
                    ->where(
                        fn ($ownedByFile) => $ownedByFile
                            ->whereColumn('distributions.result_file_id', 'result_files.id')
                            ->orWhere(
                                fn ($predatesResultFileId) => $predatesResultFileId
                                    ->whereNull('distributions.result_file_id')
                                    ->whereColumn('distributions.task_id', 'result_files.task_id')
                            )
                    )
            )
            ->update([
                'status' => ResultFile::STATUS_FAILED,
                'error' => self::NO_CONCEPTS_ERROR,
            ]);

        self::dispatch($ids->last());
    }
}
