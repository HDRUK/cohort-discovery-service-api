<?php

namespace App\Jobs;

use App\Models\CollectionMetadata;
use App\Models\ResultFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessMetadataFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private string $tag = 'ProcessMetadataFile';

    public $timeout = 120;
    public $tries = 2;
    public $backoff = 10;

    public function __construct(public int $resultFileId)
    {
        Log::info("[{$this->tag}] constructed", [
            'result_file_id' => $resultFileId,
        ]);
    }

    public function handle(): void
    {
        $file = ResultFile::with('collection')->findOrFail($this->resultFileId);

        Log::info("[{$this->tag}] starting", [
            'result_file_id' => $this->resultFileId,
            'collection_id'  => $file->collection_id,
            'path'           => $file->path,
            'file_name'      => $file->file_name,
        ]);

        $file->markProcessing();

        $stream = Storage::readStream($file->path);

        if (! $stream) {
            Log::error("[{$this->tag}] failed to open file stream", [
                'path' => $file->path,
            ]);
            throw new RuntimeException("Cannot open {$file->path}");
        }

        try {
            $headerLine = fgets($stream);
            if ($headerLine === false) {
                throw new RuntimeException('Metadata file is empty');
            }

            $dataLine = fgets($stream);
            if ($dataLine === false) {
                throw new RuntimeException('Metadata file has a header but no data row');
            }

            $header = array_map('trim', explode("\t", rtrim($headerLine, "\r\n")));
            $header[0] = preg_replace('/^\xEF\xBB\xBF/u', '', $header[0]);
            $header = array_map(fn ($h) => strtolower(trim($h)), $header);

            $cols = array_map('trim', explode("\t", rtrim($dataLine, "\r\n")));

            if (count($cols) < count($header)) {
                $cols = array_pad($cols, count($header), '');
            }

            if (count($cols) !== count($header)) {
                throw new RuntimeException(sprintf(
                    'Header/data column mismatch: header=%d data=%d',
                    count($header),
                    count($cols)
                ));
            }

            $row = array_combine($header, $cols);

            $payload = array_merge([
                'biobank'   => null,
                'protocol'  => null,
                'os'        => null,
                'bclink'    => null,
                'datamodel' => null,
                'rounding'  => null,
                'threshold' => null,
            ], $row);

            $this->storeMetadata($file, $payload);

            $extraLine = fgets($stream);
            if ($extraLine !== false && trim($extraLine) !== '') {
                Log::warning("[{$this->tag}] file contains extra rows beyond expected single metadata row", [
                    'result_file_id' => $file->id,
                ]);
            }

            $file->markDone(1);

            Log::info("[{$this->tag}] finished", [
                'result_file_id' => $file->id,
                'collection_id'  => $file->collection_id,
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

    private function storeMetadata(ResultFile $file, array $row): void
    {
        $metadata = CollectionMetadata::create([
            'collection_id' => $file->collection_id,
            'result_file_id' => $file->id,
            'biobank'   => $this->nullIfEmpty($row['biobank'] ?? null),
            'protocol'  => $this->nullIfEmpty($row['protocol'] ?? null),
            'os'        => $this->nullIfEmpty($row['os'] ?? null),
            'bclink'    => $this->nullIfEmpty($row['bclink'] ?? null),
            'datamodel' => $this->nullIfEmpty($row['datamodel'] ?? null),
            'rounding'  => $this->nullIfEmpty($row['rounding'] ?? null),
            'threshold' => $this->nullIfEmpty($row['threshold'] ?? null),
        ]);

        Log::info("[{$this->tag}] metadata inserted", [
            'collection_metadata_id' => $metadata->id,
            'collection_id' => $file->collection_id,
            'result_file_id' => $file->id,
        ]);
    }

    private function nullIfEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
