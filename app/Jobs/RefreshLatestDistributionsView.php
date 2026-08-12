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

    // The distributions and concept tables live in separate databases which may
    // use different utf8mb4 collations, so the reported-vs-central comparison is
    // forced onto a common collation to avoid an "illegal mix of collations" error.
    private const COMPARE_COLLATION = 'utf8mb4_unicode_ci';

    private string $viewName = '';

    private string $distributionTable = '';

    private string $conceptTable = '';

    private string $matTable = '';

    // The staging tables used by the atomic swap in materialiseFromView, derived once
    // here because $matTable is already back-tick quoted and can't take a suffix.
    private string $matTableNew = '';

    private string $matTableOld = '';

    private string $mysqlDb = '';

    // Bare table name; the only place the literal lives. Qualified/suffixed names are
    // derived from it (see $matTable and the staging tables above).
    private string $matTableName = 'latest_distributions_materialised';

    public function __construct()
    {
        $this->mysqlDb = config('database.connections.mysql.database');
        $omopDb        = config('database.connections.omop.database');

        $this->viewName          = $this->qualified('latest_distributions');
        $this->distributionTable = $this->qualified('distributions');
        $this->conceptTable      = $this->qualified('concept', $omopDb);
        $this->matTable          = $this->qualified($this->matTableName);
        $this->matTableNew       = $this->qualified($this->matTableName.'_new');
        $this->matTableOld       = $this->qualified($this->matTableName.'_old');
    }

    /**
     * Back-tick quote a `database`.`table` identifier. Defaults to the primary
     * (mysql) database. The only place the quoting/prefix pattern lives.
     */
    private function qualified(string $table, ?string $database = null): string
    {
        return sprintf('`%s`.`%s`', $database ?? $this->mysqlDb, $table);
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

        $collation = self::COMPARE_COLLATION;

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
                (d.category COLLATE {$collation} <> c.domain_id COLLATE {$collation}) AS domain_mismatch,
                {$domainSourceExpr} AS domain_id
            FROM {$this->distributionTable} d
            INNER JOIN {$this->conceptTable} c
                ON d.concept_id = c.concept_id
            WHERE ({$whereClause})
                AND d.concept_id IS NOT NULL
                AND d.concept_id <> 0
        ");

        $afterCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->viewName}")->count ?? 0;
        Log::info('latest_distributions view count after refresh', [
            'view'  => $this->viewName,
            'count' => $afterCount,
        ]);

        $this->materialiseFromView();
    }

    /**
     * Snapshot the freshly-rebuilt view into the materialised table so reads hit
     * pre-joined, indexed rows instead of re-running the cross-database join.
     *
     * Builds a staging copy then atomically RENAMEs it into place, so readers never
     * observe a partially-filled or empty table mid-refresh. The job only runs when the
     * underlying data has changed, so we always rebuild.
     */
    private function materialiseFromView(): void
    {
        $new = $this->matTableNew;
        $old = $this->matTableOld;
        $columns = $this->columnList();

        try {
            DB::statement("DROP TABLE IF EXISTS {$new}");
            DB::statement("CREATE TABLE {$new} LIKE {$this->matTable}");

            DB::statement("
                INSERT INTO {$new} ({$columns})
                SELECT {$columns}
                FROM {$this->viewName}
            ");

            DB::statement("DROP TABLE IF EXISTS {$old}");
            DB::statement("RENAME TABLE {$this->matTable} TO {$old}, {$new} TO {$this->matTable}");
            DB::statement("DROP TABLE IF EXISTS {$old}");

            $matCount = DB::selectOne("SELECT COUNT(*) AS count FROM {$this->matTable}")->count ?? 0;
            Log::info('latest_distributions_materialised table refilled', [
                'table' => $this->matTable,
                'count' => $matCount,
            ]);
        } catch (\Throwable $e) {
            // Leave the previous table in place on failure; clean up the staging copy.
            DB::statement("DROP TABLE IF EXISTS {$new}");
            Log::error('latest_distributions_materialised refill failed', [
                'table' => $this->matTable,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * The view's projection, mirrored by the materialised table. Single source for
     * the refill INSERT/SELECT, so the two never drift.
     *
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'id', 'collection_id', 'task_id', 'result_file_id', 'concept_id', '`count`',
            'concept_name', 'reported_domain_id', 'central_domain_id', 'domain_mismatch', 'domain_id',
        ];
    }

    private function columnList(): string
    {
        return implode(', ', $this->columns());
    }
}
