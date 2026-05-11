<?php

namespace App\Jobs;

use App\Models\ResultFile;
use App\Services\Activity\ActivityLogger;
use App\Traits\HelperFunctions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessDistributionFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use HelperFunctions;

    private string $tag = 'ProcessDistributionFile';

    public $timeout = 120;
    public $tries = 2;
    public $backoff = 10;

    private int $batchSize;

    public function __construct(public int $resultFileId)
    {
        $this->batchSize = (int) (config('system.distribution_batch_file_size') ?? 100);

        Log::info("[{$this->tag}] constructed", [
            'result_file_id' => $resultFileId,
            'batch_size'     => $this->batchSize,
        ]);
    }

    public function handle(ActivityLogger $activityLogger): void
    {
        $file = ResultFile::findOrFail($this->resultFileId);

        Log::info('[' . $this->tag . '] starting', [
            'result_file_id' => $this->resultFileId,
            'path'           => $file->path,
            'file_name'      => $file->file_name,
        ]);

        if ($file->status === ResultFile::STATUS_DONE) {
            $activityLogger->custom('result_files', 'skipped', $file, [], 'distribution_file_processing_skipped');

            return;
        }

        $activityLogger->custom('result_files', 'started', $file, [
            'processing' => [
                'batch_size' => $this->batchSize,
            ],
        ], 'distribution_file_processing_started');

        $file->markProcessing();

        $stream = Storage::readStream($file->path);
        if (! $stream) {
            Log::error('[' . $this->tag . '] Failed to open file stream', [
                'path' => $file->path,
            ]);

            $activityLogger->custom('result_files', 'failed', $file, [
                'error' => [
                    'message' => "Cannot open {$file->path}",
                ],
            ], 'distribution_file_processing_failed');

            throw new RuntimeException("Cannot open {$file->path}");
        }

        $header = null;
        $batch  = [];

        $rowsSeen = 0;
        $skipped = [
            'bad_header'        => 0,
            'col_mismatch'      => 0,
            'missing_count'     => 0,
            'missing_category'  => 0,
            'missing_name'      => 0,
            'invalid_count'     => 0,
            'invalid_alt_name'  => 0,
            'invalid_alt_count' => 0,
        ];

        $now = now();

        $codeField = $file->file_name === 'code.distribution' ? 'OMOP' : 'CODE';
        $descField = $file->file_name === 'code.distribution' ? 'OMOP_DESCR' : 'DESCRIPTION';

        $rowTemplate = [
            'collection_id'  => null,
            'task_id'        => null,
            'result_file_id' => null,

            'category'       => null,
            'name'           => null,
            'description'    => null,
            'concept_id'     => null,

            'count'          => null,
            'q1'             => null,
            'q3'             => null,
            'min'            => null,
            'max'            => null,
            'mean'           => null,
            'median'         => null,

            'created_at'     => null,
            'updated_at'     => null,
        ];

        try {
            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($header === null) {
                    if (trim($line) === '') {
                        $skipped['bad_header']++;
                        continue;
                    }

                    $tmpHeader = array_map('trim', explode("\t", $line));
                    $tmpHeader[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $tmpHeader[0]);

                    if (count(array_filter($tmpHeader, fn ($h) => $h !== '')) === 0) {
                        $skipped['bad_header']++;
                        continue;
                    }

                    $header = $tmpHeader;
                    continue;
                }

                $cols = explode("\t", $line);
                if (count($cols) < count($header)) {
                    $cols = array_pad($cols, count($header), '');
                }

                if (count($cols) !== count($header)) {
                    $skipped['col_mismatch']++;
                    continue;
                }

                $row = array_combine($header, $cols);

                if (! isset($row['COUNT'])) {
                    $skipped['missing_count']++;
                    continue;
                }


                $category = $this->normaliseNullable($row['CATEGORY']);
                if ($category === null) {
                    $skipped['missing_category']++;
                    continue;
                }

                $name = $this->normaliseNullable($row[$codeField] ?? $row['CODE']);
                if ($name === null) {
                    $skipped['missing_name']++;
                    continue;
                }

                $description = $this->normaliseNullable($row[$descField]);
                $description = $description ?? $name;

                $count = $this->normaliseInt($row['COUNT']);

                if ($count === null) {
                    $skipped['invalid_count']++;
                    continue;
                }
                $conceptId = $this->normaliseStrictInt($row[$codeField] ?? $row['CODE']);

                $rowsSeen++;

                $base = [
                    'collection_id'  => $file->collection_id,
                    'task_id'        => $file->task_id,
                    'result_file_id' => $file->id,

                    'category'       => $category,
                    'name'           => $name,
                    'description'    => $description,
                    'concept_id'     => $conceptId,

                    'count'          => $count,
                    'q1'             => $this->normaliseNullable($row['Q1']),
                    'q3'             => $this->normaliseNullable($row['Q3']),
                    'min'            => $this->normaliseNullable($row['MIN']),
                    'max'            => $this->normaliseNullable($row['MAX']),
                    'mean'           => $this->normaliseNullable($row['MEAN']),
                    'median'         => $this->normaliseNullable($row['MEDIAN']),

                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];

                $batch[] = array_merge($rowTemplate, $base);

                if (! empty($row['ALTERNATIVES'])) {
                    $segments = explode('^', trim((string) $row['ALTERNATIVES'], '^'));
                    foreach ($segments as $seg) {
                        if (strpos($seg, '|') === false) {
                            continue;
                        }

                        [$altName, $altCount] = explode('|', $seg, 2);

                        $altName = trim((string) $altName);
                        $altCount = trim((string) $altCount);

                        if ($altName === '') {
                            $skipped['invalid_alt_name']++;
                            continue;
                        }

                        if ($altCount === '' || ! is_numeric($altCount)) {
                            $skipped['invalid_alt_count']++;
                            continue;
                        }

                        $altRow = [
                            'collection_id'  => $file->collection_id,
                            'task_id'        => $file->task_id,
                            'result_file_id' => $file->id,

                            'category'       => $category,
                            'name'           => $altName,
                            'description'    => $altName,
                            'concept_id'     => null,

                            'count'          => (int) $altCount,

                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];

                        $batch[] = array_merge($rowTemplate, $altRow);

                    }
                }

                if (count($batch) >= $this->batchSize) {
                    $this->persistBatchUpsert($batch);
                    $batch = [];
                    gc_collect_cycles();
                }
            }

            if (! empty($batch)) {
                $this->persistBatchUpsert($batch);
            }

            Log::info('[' . $this->tag . ']  Refreshing DistributionConcepts view');
            RefreshDistributionConceptsView::dispatch();

            $file->markDone($rowsSeen);

            $activityLogger->processed('result_files', $file, [
                'result' => [
                    'rows_seen' => $rowsSeen,
                    'skipped' => $skipped,
                ],
            ], 'distribution_file_processed');

            Log::info('[' . $this->tag . '] finished', [
                'result_file_id' => $file->id,
                'task_id'        => $file->task_id,
                'rows_seen'      => $rowsSeen,
                'skipped'        => $skipped,
            ]);
        } finally {
            fclose($stream);
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($file = ResultFile::find($this->resultFileId)) {
            $file->markFailed($e->getMessage());

            app(ActivityLogger::class)->failed(
                'result_files',
                $file,
                $e,
                [],
                'distribution_file_processing_failed'
            );
        }
    }

    private function persistBatchUpsert(array $rows): void
    {
        $uniqueBy = ['task_id', 'result_file_id', 'category', 'name'];

        $update = [
            'collection_id',
            'description',
            'concept_id',
            'count',
            'q1', 'q3', 'min', 'max', 'mean', 'median',
            'updated_at',
        ];

        DB::table('distributions')->upsert($rows, $uniqueBy, $update);
    }
}
