<?php

namespace App\Traits;

trait HelperFunctions
{
    /**
     * Convert a TSV string into an array of associative arrays.
     *
     * @param string $tsv
     * @return array
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

        if (is_numeric($requested) && (int)$requested > 0) {
            return min((int)$requested, $max);
        }

        return config('api.per_page', 25);
    }
}
