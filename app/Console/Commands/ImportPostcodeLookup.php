<?php

namespace App\Console\Commands;

use App\Traits\StreamsCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportPostcodeLookup extends Command
{
    use StreamsCsv;

    protected $signature = 'postcode:import-lookup
                            {--file= : Path to ONS postcode CSV (PCD_OA21_LSOA21_MSOA21_LAD_MAY26_UK_LU.csv)}';

    protected $description = 'Import active UK postcodes from the ONS postcode lookup CSV into postcode_lookup.';

    private const CHUNK_SIZE = 500;
    private const LOG_EVERY = 50000;

    public function handle(): int
    {
        $file = (string) $this->option('file');

        if (! $file) {
            $this->error('--file is required.');

            return self::FAILURE;
        }

        $this->info("Importing from: {$file}");
        $this->info('Skipping terminated postcodes (doterm non-empty).');

        $chunk = [];
        $imported = 0;
        $skipped = 0;
        $rowNumber = 0;

        foreach ($this->csvRows($file) as $row) {
            $rowNumber++;

            // Skip terminated postcodes
            if (! empty($row['doterm'])) {
                $skipped++;
                continue;
            }

            $postcode = strtoupper(str_replace(' ', '', (string) ($row['pcds'] ?? '')));

            if (! $postcode) {
                $skipped++;
                continue;
            }

            $chunk[] = [
                'postcode' => $postcode,
                'lsoa21cd' => $row['lsoa21cd'] ?? null,
                'lsoa21nm' => $row['lsoa21nm'] ?? null,
                'ladcd'    => $row['ladcd'] ?? null,
                'ladnm'    => $row['ladnm'] ?? null,
            ];

            if (count($chunk) >= self::CHUNK_SIZE) {
                DB::table('postcode_lookup')->upsert($chunk, ['postcode']);
                $imported += count($chunk);
                $chunk = [];
            }

            if ($rowNumber % self::LOG_EVERY === 0) {
                $this->line("  {$rowNumber} rows processed, {$imported} imported...");
            }
        }

        if (! empty($chunk)) {
            DB::table('postcode_lookup')->upsert($chunk, ['postcode']);
            $imported += count($chunk);
        }

        $this->newLine();
        $this->info('Import complete.');
        $this->line("  Imported: {$imported}");
        $this->line("  Skipped:  {$skipped}");

        return self::SUCCESS;
    }
}
