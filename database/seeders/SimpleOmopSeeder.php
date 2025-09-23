<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Omop\Concept;

class SimpleOmopSeeder extends Seeder
{
    /**
     * Seed the OMOP concept table with Male and Female.
     */
    public function run(): void
    {
        Concept::insert([
            [
                'concept_id'        => 8507,
                'concept_name'      => 'Male',
                'domain_id'         => 'Gender',
                'vocabulary_id'     => 'Gender',
                'concept_class_id'  => 'Gender',
                'standard_concept'  => 'S',
                'concept_code'      => 'M',
                'valid_start_date'  => now(),
                'valid_end_date'    => now()->addYears(100),
                'invalid_reason'    => null,
            ],
            [
                'concept_id'        => 8532,
                'concept_name'      => 'Female',
                'domain_id'         => 'Gender',
                'vocabulary_id'     => 'Gender',
                'concept_class_id'  => 'Gender',
                'standard_concept'  => 'S',
                'concept_code'      => 'F',
                'valid_start_date'  => now(),
                'valid_end_date'    => now()->addYears(100),
                'invalid_reason'    => null,
            ],
        ]);
    }
}
