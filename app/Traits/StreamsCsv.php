<?php

namespace App\Traits;

// note - ignoring in phpstan because it is only used in database seeding
// @phpstan-ignore-next-line
trait StreamsCsv
{
    /**
     * Stream rows from a CSV as associative arrays using the header row.
     *
     * @return \Generator<array<string, string|null>>
     */
    protected function csvRows(string $fullPath): \Generator
    {
        if (!is_readable($fullPath)) {
            throw new \RuntimeException("CSV not readable: {$fullPath}");
        }

        $fh = fopen($fullPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Unable to open CSV: {$fullPath}");
        }

        try {
            $header = fgetcsv($fh);
            if ($header === false) {
                return;
            }

            // Trim + strip UTF-8 BOM from first header cell if present
            $header = array_map(function ($h) {
                $h = preg_replace('/^\xEF\xBB\xBF/', '', $h ?? '');
                return trim((string) $h);
            }, $header);

            while (($row = fgetcsv($fh)) !== false) {
                // Skip empty lines
                if ($row === [null] || $row === false) {
                    continue;
                }

                // Pad short rows to header length
                if (count($row) < count($header)) {
                    $row = array_pad($row, count($header), null);
                }

                yield array_combine($header, $row);
            }
        } finally {
            fclose($fh);
        }
    }
}
