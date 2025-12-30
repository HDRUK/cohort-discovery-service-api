<?php

namespace App\Jobs;

use DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshDistributionConceptsView implements ShouldQueue
{
    use Queueable;

    private string $viewName = '';

    private string $distributionTable = '';

    private string $conceptTable = '';

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $mysqlDb = config('database.connections.mysql.database');
        $omopDb  = config('database.connections.omop.database');

        $this->viewName         = "`{$mysqlDb}`.`distribution_concepts`";
        $this->distributionTable = "`{$mysqlDb}`.`distributions`";
        $this->conceptTable      = "`{$omopDb}`.`concept`";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW {$this->viewName} AS
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
                FROM {$this->distributionTable} d
                INNER JOIN {$this->conceptTable} c
                    ON d.concept_id = c.concept_id;
        ");
    }
}
