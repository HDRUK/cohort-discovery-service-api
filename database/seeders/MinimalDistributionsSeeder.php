<?php

namespace Database\Seeders;

use App\Models\Distribution;
use App\Traits\StreamsCsv;
use Illuminate\Database\Seeder;

class MinimalDistributionsSeeder extends Seeder
{
    use StreamsCsv;

    private int $chunkSize = 500;

    public function run(): void
    {
        $path = database_path('seeders/data/minimal_distributions.csv');
        $this->command->info("Seeding concepts from: {$path}");

        $generator = $this->csvRows($path);

        $buffer = [];
        $count = 0;

        $toNull = fn ($d) => in_array($d, [' ', '', null], true)
            ? null
            : $d;

        foreach ($generator as $row) {
            unset($row['id']);
            $row = array_map($toNull, $row);
            $buffer[] = $row;

            if (count($buffer) >= $this->chunkSize) {
                Distribution::query()->insert(
                    $buffer,
                );
                $bufferSize = count($buffer);
                $this->command?->info("Chunk completed. Concepts upserted: {$bufferSize}");
                $count += $bufferSize;
                $buffer = [];
            }
        }

        if ($buffer) {
            Distribution::query()->insert(
                $buffer,
            );
            $count += count($buffer);
        }

        $this->command?->info("Distributions upserted: {$count}");
    }
}
