<?php

namespace App\Jobs;

use DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
        $beforeCount = null;
        try {
            $beforeCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        } catch (\Throwable $e) {
            Log::warning('distribution_concepts view count failed before refresh', [
                'view' => $this->viewName,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('distribution_concepts view count before refresh', [
            'view' => $this->viewName,
            'count' => $beforeCount,
        ]);

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

        $afterCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        Log::info('distribution_concepts view count after refresh', [
            'view' => $this->viewName,
            'count' => $afterCount,
        ]);
    }
}
