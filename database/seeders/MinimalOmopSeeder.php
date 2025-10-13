<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use App\Models\Omop\Concept;
use App\Models\Omop\ConceptAncestor;
use App\Traits\StreamsCsv;

class MinimalOmopSeeder extends Seeder
{
    use StreamsCsv;
    private int $chunkSize = 500;

    public function run(): void
    {
        $this->seedConcepts('omop/minimal_concept.csv');
        $this->seedConceptAncestors('omop/minimal_concept_ancestor.csv');
    }

    private function seedConcepts(string $relativePath): void
    {
        $path = database_path('seeders/data/' . $relativePath);
        $this->command->info("Seeding concepts from: {$path}");

        $generator = $this->csvRows($path);

        $buffer = [];
        $count = 0;

        $toNull = fn($d) => in_array($d, ['0000-00-00', '0000-00-00 00:00:00', '', null], true) ? null : $d;

        foreach ($generator as $row) {
            $buffer[] = [
                'concept_id'          => (int) $row['concept_id'],
                'concept_name'        => $row['concept_name'],
                'domain_id'           => $row['domain_id'],
                'vocabulary_id'       => $row['vocabulary_id'],
                'concept_class_id'    => $row['concept_class_id'],
                'standard_concept'    => $row['standard_concept'] ?: null,
                'concept_code'        => $row['concept_code'] ?: null,
                'valid_start_date'    => $toNull($row['valid_start_date']),
                'valid_end_date'      => $toNull($row['valid_end_date']),
                'invalid_reason'      => $row['invalid_reason'] ?: null,
            ];

            if (count($buffer) >= $this->chunkSize) {
                Concept::upsert(
                    $buffer,
                    ['concept_id'],
                    [
                        'concept_name',
                        'domain_id',
                        'vocabulary_id',
                        'concept_class_id',
                        'standard_concept',
                        'concept_code',
                        'valid_start_date',
                        'valid_end_date',
                        'invalid_reason',
                    ]
                );
                $bufferSize = count($buffer);
                $this->command?->info("Chunk completed. Concepts upserted: {$bufferSize}");
                $count += $bufferSize;
                $buffer = [];
            }
        }

        if ($buffer) {
            Concept::upsert(
                $buffer,
                ['concept_id'],
                [
                    'concept_name',
                    'domain_id',
                    'vocabulary_id',
                    'concept_class_id',
                    'standard_concept',
                    'concept_code',
                    'valid_start_date',
                    'valid_end_date',
                    'invalid_reason',
                ]
            );
            $count += count($buffer);
        }

        $this->command?->info("Concepts upserted: {$count}");
    }

    private function seedConceptAncestors(string $relativePath): void
    {
        $path = database_path('seeders/data/' . $relativePath);
        $this->command?->info("Seeding concept_ancestor from: {$path}");

        $generator = $this->csvRows($path);

        $buffer = [];
        $count = 0;

        foreach ($generator as $row) {
            $buffer[] = [
                'ancestor_concept_id'       => (int) $row['ancestor_concept_id'],
                'descendant_concept_id'     => (int) $row['descendant_concept_id'],
                'min_levels_of_separation'  => (int) $row['min_levels_of_separation'],
                'max_levels_of_separation'  => (int) $row['max_levels_of_separation'],
            ];

            if (count($buffer) >= $this->chunkSize) {
                ConceptAncestor::upsert(
                    $buffer,
                    ['ancestor_concept_id', 'descendant_concept_id'],
                    ['min_levels_of_separation', 'max_levels_of_separation']
                );
                $count += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer) {
            ConceptAncestor::upsert(
                $buffer,
                ['ancestor_concept_id', 'descendant_concept_id'],
                ['min_levels_of_separation', 'max_levels_of_separation']
            );
            $count += count($buffer);
        }

        $this->command?->info("Concept_ancestor upserted: {$count}");
    }
}
