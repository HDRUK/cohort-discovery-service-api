<?php

namespace App\Console\Commands;

use App\Traits\StreamsCsv;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportLsoaCentroids extends Command
{
    use StreamsCsv;

    protected $signature = 'postcode:import-centroids
                            {--england-wales= : Path to lsoa_latlong.csv (LSOACD, latitude, longitude)}
                            {--scotland= : Path to scotland_locations.csv (location_source_value, latitude, longitude)}';

    protected $description = 'Import LSOA/Data Zone centroids into lsoa_centroids.';

    private const CHUNK_SIZE = 500;

    public function handle(): int
    {
        $englandWalesFile = (string) $this->option('england-wales');
        $scotlandFile = (string) $this->option('scotland');

        if (! $englandWalesFile && ! $scotlandFile) {
            $this->error('At least one of --england-wales or --scotland is required.');

            return self::FAILURE;
        }

        if ($englandWalesFile) {
            $this->info("Importing England/Wales centroids from: {$englandWalesFile}");
            $count = $this->importCentroids(
                $englandWalesFile,
                fn (array $row) => [
                    'lsoa_code' => trim((string) ($row['LSOACD'] ?? '')),
                    'latitude'  => (float) ($row['latitude'] ?? 0),
                    'longitude' => (float) ($row['longitude'] ?? 0),
                ]
            );
            $this->info("  {$count} England/Wales centroids imported.");
        }

        if ($scotlandFile) {
            $this->info("Importing Scotland centroids from: {$scotlandFile}");
            $count = $this->importCentroids(
                $scotlandFile,
                fn (array $row) => [
                    'lsoa_code' => trim((string) ($row['location_source_value'] ?? '')),
                    'latitude'  => (float) ($row['latitude'] ?? 0),
                    'longitude' => (float) ($row['longitude'] ?? 0),
                ],
                "\t"
            );
            $this->info("  {$count} Scotland centroids imported.");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function importCentroids(string $file, callable $mapper, string $delimiter = ','): int
    {
        $chunk = [];
        $imported = 0;

        foreach ($this->csvRows($file, $delimiter) as $row) {
            $record = $mapper($row);

            if (! $record['lsoa_code']) {
                continue;
            }

            $chunk[] = $record;

            if (count($chunk) >= self::CHUNK_SIZE) {
                DB::table('lsoa_centroids')->upsert($chunk, ['lsoa_code']);
                $imported += count($chunk);
                $chunk = [];
            }
        }

        if (! empty($chunk)) {
            DB::table('lsoa_centroids')->upsert($chunk, ['lsoa_code']);
            $imported += count($chunk);
        }

        return $imported;
    }
}
