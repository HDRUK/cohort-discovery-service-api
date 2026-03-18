<?php

namespace App\Jobs;

use App\Models\ResultFile;
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

    private string $tag = 'ProcessDistributionFile';

    public $timeout = 120;
    public $tries = 2;
    public $backoff = 10;

    private int $batchSize;

    public function __construct(public int $resultFileId)
    {
        $this->batchSize = (int) (config('system.distribution_batch_file_size') ?? 500);

        Log::info("[{$this->tag}] constructed", [
            'result_file_id' => $resultFileId,
            'batch_size'     => $this->batchSize,
        ]);
    }

    public function handle(): void
    {
        $file = ResultFile::findOrFail($this->resultFileId);

        Log::info('[' . $this->tag . '] starting', [
            'result_file_id' => $this->resultFileId,
            'path'           => $file->path,
            'file_name'      => $file->file_name,
        ]);

        if ($file->status === ResultFile::STATUS_DONE) {
            return;
        }

        $file->markProcessing();

        $stream = Storage::readStream($file->path);
        if (! $stream) {
            Log::error('[' . $this->tag . '] Failed to open file stream', [
                'path' => $file->path,
            ]);
            throw new RuntimeException("Cannot open {$file->path}");
        }

        $header = null;
        $batch  = [];

        $rowsSeen = 0;
        $skipped = [
            'bad_header'     => 0,
            'col_mismatch'   => 0,
            'missing_count'  => 0,
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

                $rowsSeen++;

                $category = isset($row['CATEGORY']) ? trim((string) $row['CATEGORY']) : null;

                $name = $row[$codeField] ?? $row['CODE'] ?? null;
                $name = $name !== null ? trim((string) $name) : null;

                $conceptIdRaw = $row[$codeField] ?? $row['CODE'] ?? null;
                $conceptId = ($conceptIdRaw !== null && $conceptIdRaw !== '')
                    ? (int) $conceptIdRaw
                    : null;

                $base = [
                    'collection_id'  => $file->collection_id,
                    'task_id'        => $file->task_id,
                    'result_file_id' => $file->id,

                    'category'       => $category,
                    'name'           => $name,
                    'description'    => $row[$descField] ?? null,
                    'concept_id'     => $conceptId,

                    'count'          => (int) $row['COUNT'],
                    'q1'             => $row['Q1'] ?? null,
                    'q3'             => $row['Q3'] ?? null,
                    'min'            => $row['MIN'] ?? null,
                    'max'            => $row['MAX'] ?? null,
                    'mean'           => $row['MEAN'] ?? null,
                    'median'         => $row['MEDIAN'] ?? null,

                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];

                $batch[] = array_merge($rowTemplate, $base);

                if (! empty($row['ALTERNATIVES'])) {
                    $segments = explode('^', trim((string) $row['ALTERNATIVES'], '^'));
                    foreach ($segments as $seg) {
                        if (strpos($seg, '|') !== false) {
                            [$altName, $altCount] = explode('|', $seg, 2);

                            $altName = trim((string) $altName);

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
                }

                if (count($batch) >= $this->batchSize) {
                    $this->persistBatchUpsert($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                $this->persistBatchUpsert($batch);
            }

            Log::info('[' . $this->tag . ']  Refreshing DistributionConcepts view');
            RefreshDistributionConceptsView::dispatch();

            $file->markDone($rowsSeen);

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
