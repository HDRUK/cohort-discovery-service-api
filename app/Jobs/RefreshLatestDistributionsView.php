<?php

namespace App\Jobs;

use App\Models\Collection;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class RefreshLatestDistributionsView implements ShouldQueue
{
    use Queueable;

    private string $viewName = '';

    private string $distributionTable = '';

    private string $conceptTable = '';

    public function __construct()
    {
        $mysqlDb = config('database.connections.mysql.database');
        $omopDb  = config('database.connections.omop.database');

        $this->viewName          = "`{$mysqlDb}`.`latest_distributions`";
        $this->distributionTable = "`{$mysqlDb}`.`distributions`";
        $this->conceptTable      = "`{$omopDb}`.`concept`";
    }

    public function handle(): void
    {
        $beforeCount = null;
        try {
            $beforeCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        } catch (\Throwable $e) {
            Log::warning('latest_distributions view count failed before refresh', [
                'view'  => $this->viewName,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('latest_distributions view count before refresh', [
            'view'  => $this->viewName,
            'count' => $beforeCount,
        ]);

        $resultFileIds = Collection::query()
            ->whereHas('latestSuccessfulConceptResultFile')
            ->withAggregate('latestSuccessfulConceptResultFile as latest_result_file_id', 'id')
            ->pluck('latest_result_file_id')
            ->filter()
            ->unique()
            ->values();

        $whereClause = '1 = 0';

        if ($resultFileIds->isNotEmpty()) {
            $idList = $resultFileIds
                ->map(fn ($id) => (int) $id)
                ->implode(',');

            $whereClause = "d.result_file_id IN ({$idList})";
        } else {
            Log::info('latest_distributions view refresh found no result files; creating empty view', [
                'view' => $this->viewName,
            ]);
        }

        // The effective `domain_id` (what the app filters/displays on) is sourced
        // from the custodian-reported category by default, or the central OMOP
        // vocabulary when the flag is on. Both are always stored for drift telemetry.
        $domainSourceExpr = Feature::active('distribution-use-central-domain')
            ? 'c.domain_id'   // central OMOP vocabulary
            : 'd.category';   // custodian-reported / origin (default)

        DB::statement("
            CREATE OR REPLACE VIEW {$this->viewName} AS
            SELECT
                d.id,
                d.collection_id,
                d.task_id,
                d.result_file_id,
                d.concept_id,
                d.count,
                c.concept_name,
                d.category AS reported_domain_id,
                c.domain_id AS central_domain_id,
                (d.category <> c.domain_id) AS domain_mismatch,
                {$domainSourceExpr} AS domain_id
            FROM {$this->distributionTable} d
            INNER JOIN {$this->conceptTable} c
                ON d.concept_id = c.concept_id
            WHERE {$whereClause}
        ");

        $afterCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        Log::info('latest_distributions view count after refresh', [
            'view'  => $this->viewName,
            'count' => $afterCount,
        ]);
    }
}
