<?php

namespace Database\Seeders;

use DB;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DistributionConceptViewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $viewName = config('database.connections.omop.database') . '.distribution_concepts';
        $distributionTable = config('database.connections.omop.database') . '.distributions';
        $conceptTable = config('database.connections.omop.database') . '.concepts';

        DB::statement("
            CREATE OR REPLACE VIEW {$viewName} AS
                SELECT
                    d.id AS distribution_id,
                    d.collection_id,
                    d.task_id,
                    d.name AS distribution_name,
                    d.category,
                    d.description,
                    d.concept_id,
                    c.concept_name,
                    c.domain_id,
                    c.vocabulary_id,
                    c.concept_class_id,
                    c.standard_concept,
                    c.concept_code,
                    d.count,
                    d.q1,
                    d.q3,
                    d.min,
                    d.max,
                    d.mean,
                    d.median,
                    d.created_at,
                    d.updated_at
                FROM {$distributionTable} d
                LEFT JOIN {$conceptTable} c
                    ON d.concept_id = c.concept_id;
        ");        
    }
}
