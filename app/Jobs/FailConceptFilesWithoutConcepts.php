<?php

namespace App\Jobs;

use App\Models\Distribution;
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

    private const NO_CONCEPTS_ERROR = 'No concepts found in file';

    public $tries = 1;

    public function handle(): void
    {
        ResultFile::query()
            ->where('file_name', 'code.distribution')
            ->where('status', ResultFile::STATUS_DONE)
            ->lazyById()
            ->each(function (ResultFile $file) {
                $hasConcepts = Distribution::query()
                    ->where('task_id', $file->task_id)
                    ->where('concept_id', '>', 0)
                    ->exists();

                if ($hasConcepts) {
                    return;
                }

                DB::table('result_files')
                    ->where('id', $file->id)
                    ->update([
                        'status' => ResultFile::STATUS_FAILED,
                        'error' => self::NO_CONCEPTS_ERROR,
                    ]);
            });

        RefreshDistributionConceptsView::dispatch();
        RefreshLatestDistributionsView::dispatch();
    }
}
