<?php

namespace App\Jobs;

use App\Models\Distribution;
use App\Models\ResultFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessDistributionFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $timeout = 900;

    public $tries = 3;

    public $backoff = [30, 120, 300];

    public $batchSize = 500;

    public function __construct(public int $resultFileId)
    {
    }

    public function handle(): void
    {
        $file = ResultFile::findOrFail($this->resultFileId);

        if ($file->status === ResultFile::STATUS_DONE) {
            return;
        }

        $file->markProcessing();

        $stream = Storage::readStream($file->path);
        if (! $stream) {
            \Log::error('Failed to open file stream', [
                'path'    => $file->path,
            ]);
            throw new RuntimeException("Cannot open {$file->path}");
        }

        $header = null;
        $batch = [];
        $rowsProcessed = 0;
        $now = now();

        $codeField = $file->file_name === 'code.distribution' ? 'OMOP' : 'CODE';
        $descField = $file->file_name === 'code.distribution' ? 'OMOP_DESCR' : 'DESCRIPTION';

        try {
            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($header === null) {
                    $header = preg_split("/\t/", $line);
                    if (! $header) {
                        continue;
                    }

                    $header[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $header[0]);

                    continue;
                }

                $cols = preg_split("/\t/", $line, -1);
                if (count($cols) !== count($header)) {
                    continue;
                }

                $row = array_combine($header, $cols);
                if (! isset($row['COUNT'])) {
                    continue;
                }

                $conceptId = $row[$codeField] ?? $row['CODE'] ?? null;
                $conceptId = $conceptId !== null && $conceptId !== '' ? (int) $conceptId : null;

                $base = [
                    'collection_id' => $file->collection_id,
                    'task_id' => $file->task_id,
                    'category' => $row['CATEGORY'] ?? null,
                    'name' => $row[$codeField] ?? $row['CODE'] ?? null,
                    'description' => $row[$descField] ?? null,
                    'concept_id' => $conceptId,
                    'count' => (int) $row['COUNT'],
                    'q1' => $row['Q1'] ?? null,
                    'q3' => $row['Q3'] ?? null,
                    'min' => $row['MIN'] ?? null,
                    'max' => $row['MAX'] ?? null,
                    'mean' => $row['MEAN'] ?? null,
                    'median' => $row['MEDIAN'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $batch[] = $base;

                if (! empty($row['ALTERNATIVES'])) {
                    $segments = explode('^', trim($row['ALTERNATIVES'], '^'));
                    foreach ($segments as $seg) {
                        if (strpos($seg, '|') !== false) {
                            [$name, $count] = explode('|', $seg, 2);
                            $batch[] = [
                                'collection_id' => $file->collection_id,
                                'task_id' => $file->task_id,
                                'category' => $row['CATEGORY'] ?? null,
                                'name' => (string) $name,
                                'description' => (string) $name,
                                'count' => (int) $count,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                if (count($batch) >= $this->batchSize) {
                    $this->persistBatchWithCreate($batch);
                    $rowsProcessed += count($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                $this->persistBatchWithCreate($batch);
                $rowsProcessed += count($batch);
            }

            $file->markDone($rowsProcessed);
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

    private function persistBatchWithCreate(array $rows): void
    {
        foreach ($rows as $data) {
            Distribution::create($data);
        }

        RefreshDistributionConceptsView::dispatch();
        // note - to be revisited
        //      - this can copy over ancestors locally
        //        based on what distributions we have
        //      - instead of having to use the full concept_ancestor table
        // PopulateLocalConceptAncestors::dispatch();

    }
}
