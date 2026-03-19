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

    private string $collectionTable = '';

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
        $this->collectionTable   = "`{$mysqlDb}`.`collections`";
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
                latest_distribution.concept_id,
                c.concept_name,
                c.concept_name AS description,
                c.domain_id,
                c.vocabulary_id,
                c.concept_class_id AS concept_class,
                c.standard_concept,
                c.concept_code,
                SUM(latest_distribution.count) AS count,
                COUNT(*) AS ncollections,
                CASE
                    WHEN MIN(CASE WHEN col.is_synthetic THEN 1 ELSE 0 END) = 1 THEN 1
                    ELSE 0
                END AS all_synthetic
            FROM (
                SELECT
                    ranked.collection_id,
                    ranked.concept_id,
                    ranked.count
                FROM (
                    SELECT
                        x.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY x.collection_id, x.concept_id
                            ORDER BY x.updated_at DESC, x.created_at DESC, x.id DESC
                        ) AS rn
                    FROM {$this->distributionTable} x
                ) ranked
                WHERE ranked.rn = 1
            ) latest_distribution
            INNER JOIN {$this->conceptTable} c
                ON latest_distribution.concept_id = c.concept_id
            INNER JOIN {$this->collectionTable} col
                ON latest_distribution.collection_id = col.id
            GROUP BY
                latest_distribution.concept_id,
                c.concept_name,
                c.domain_id,
                c.vocabulary_id,
                c.concept_class_id,
                c.standard_concept,
                c.concept_code
        ");

        $afterCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        Log::info('distribution_concepts view count after refresh', [
            'view' => $this->viewName,
            'count' => $afterCount,
        ]);
    }
}
