<?php

namespace App\Traits;

use Illuminate\Console\Command;
use Closure;

trait HelperFunctions
{
    /**
     * Convert a TSV string into an array of associative arrays.
     */
    public function tsvToArray(string $tsv): array
    {
        $lines = explode("\n", trim($tsv));
        if (count($lines) < 2) {
            return []; // No data
        }

        $headers = explode("\t", array_shift($lines));
        $data = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = explode("\t", $line);
            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $values[$index] ?? null;
            }

            $data[] = $row;
        }

        return $data;
    }

    public function resolvePerPage(int $max = 100): int
    {
        $requested = request()->query('per_page');

        if (is_numeric($requested) && (int) $requested > 0) {
            return min((int) $requested, $max);
        }

        return config('api.per_page', 25);
    }

    protected function processCsvFile(
        Command $cmd,
        string $file,
        string $delimiter,
        array $requiredCols,
        Closure $processRow
    ): void {
        if (! $file) {
            $cmd->error('Missing required option: --file=/path/to/users.csv');
            throw new \RuntimeException('Missing file');
        }

        if (! file_exists($file) || ! is_readable($file)) {
            $cmd->error("File [{$file}] does not exist or is not readable.");
            throw new \RuntimeException('File not readable');
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $cmd->error("Could not open file [{$file}].");
            throw new \RuntimeException('Could not open file');
        }

        try {
            $header = fgetcsv($handle, 0, $delimiter);
            if (! $header) {
                $cmd->error('CSV appears to be empty.');
                throw new \RuntimeException('Empty CSV');
            }

            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            $missing = array_values(array_diff($requiredCols, $header));
            if (! empty($missing)) {
                $cmd->error('CSV is missing required column(s): ' . implode(', ', $missing));
                $cmd->line('Expected header: ' . implode(',', $requiredCols));
                throw new \RuntimeException('Missing required columns');
            }

            $rowNumber = 1;
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $rowNumber++;

                if (count($row) < count($header)) {
                    $row = array_pad($row, count($header), null);
                }

                /** @var array<string, string|null> $data */
                $data = array_combine($header, $row);

                $processRow($data, $rowNumber);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Generate a random password of 7 characters from:
     *  - uppercase letters
     *  - lowercase letters
     *  - digits
     *  - special characters
     */
    protected function generatePassword(int $length = 7): string
    {
        if ($length < 3) {
            throw new \InvalidArgumentException('Password length must be at least 3.');
        }

        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $digits    = '0123456789';
        $special   = '!?.,;:#^%';

        $all = $uppercase . $lowercase . $digits . $special;

        $password = [];

        // Ensure at least one of each required type
        $password[] = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password[] = $digits[random_int(0, strlen($digits) - 1)];
        $password[] = $special[random_int(0, strlen($special) - 1)];

        for ($i = 3; $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        // Shuffle
        for ($i = count($password) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

}
