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

    // distributions and concept live in separate schemas which may use different
    // utf8mb4 collations, so the reported-vs-central comparison is forced onto a
    // common collation to avoid an "illegal mix of collations" error.
    private const COMPARE_COLLATION = 'utf8mb4_unicode_ci';

    // These objects all live in the default (primary) schema, so they need no schema
    // prefix — only the OMOP `concept` table does (see conceptTable()).
    private const VIEW = 'latest_distributions';

    private const MAT_TABLE = 'latest_distributions_materialised';

    public function handle(): void
    {
        $this->rebuildView();
        $this->materialiseFromView();
    }

    /**
     * Rebuild the `latest_distributions` view over each collection's latest successful
     * concept result file, joined to the OMOP `concept` table.
     */
    private function rebuildView(): void
    {
        $resultFileIds = Collection::query()
            ->whereHas('latestSuccessfulConceptResultFile')
            ->withAggregate('latestSuccessfulConceptResultFile as latest_result_file_id', 'id')
            ->pluck('latest_result_file_id')
            ->filter()
            ->unique()
            ->values();

        $whereClause = '1 = 0';
        if ($resultFileIds->isNotEmpty()) {
            $idList = $resultFileIds->map(fn ($id) => (int) $id)->implode(',');
            $whereClause = "d.result_file_id IN ({$idList})";
        } else {
            Log::info('latest_distributions refresh found no result files; building an empty view');
        }

        // The effective `domain_id` (what the app filters/displays on) is the custodian-
        // reported category by default, or the central OMOP vocabulary when the flag is
        // on. Both are always stored for drift telemetry.
        $domainSourceExpr = Feature::active('distribution-use-central-domain')
            ? 'c.domain_id'   // central OMOP vocabulary
            : 'd.category';   // custodian-reported / origin (default)

        $view = self::VIEW;
        $concept = $this->conceptTable();
        $collation = self::COMPARE_COLLATION;

        DB::statement("
            CREATE OR REPLACE VIEW `{$view}` AS
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
            FROM `distributions` d
            INNER JOIN {$concept} c
                ON d.concept_id = c.concept_id
            WHERE ({$whereClause})
                AND d.concept_id IS NOT NULL
                AND d.concept_id <> 0
        ");
    }

    /**
     * Snapshot the freshly-rebuilt view into the materialised table so reads hit
     * pre-joined, indexed rows instead of re-running the cross-schema join.
     *
     * Fills a staging table then atomically RENAMEs it into place, so concurrent
     * readers never observe a half-filled or empty table mid-refresh. The job only
     * runs when the underlying data has changed, so we always rebuild — there is no
     * cheaper up-to-date check than the rebuild itself.
     */
    private function materialiseFromView(): void
    {
        $view = self::VIEW;
        $mat = self::MAT_TABLE;
        $new = $mat.'_new';
        $old = $mat.'_old';
        $columns = $this->columnList();

        try {
            DB::statement("DROP TABLE IF EXISTS `{$new}`");
            DB::statement("CREATE TABLE `{$new}` LIKE `{$mat}`");
            DB::statement("INSERT INTO `{$new}` ({$columns}) SELECT {$columns} FROM `{$view}`");

            DB::statement("DROP TABLE IF EXISTS `{$old}`");
            DB::statement("RENAME TABLE `{$mat}` TO `{$old}`, `{$new}` TO `{$mat}`");
            DB::statement("DROP TABLE IF EXISTS `{$old}`");

            $count = DB::selectOne("SELECT COUNT(*) AS count FROM `{$mat}`")->count ?? 0;
            Log::info('latest_distributions_materialised refilled', ['count' => $count]);
        } catch (\Throwable $e) {
            // Leave the live table untouched on failure; clean up the staging copy.
            DB::statement("DROP TABLE IF EXISTS `{$new}`");
            Log::error('latest_distributions_materialised refill failed', ['error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * OMOP `concept` lives in a separate schema, so it must be schema-qualified.
     */
    private function conceptTable(): string
    {
        $schema = config('database.connections.omop.database');

        return "`{$schema}`.`concept`";
    }

    /**
     * The view's projection, mirrored by the materialised table. Single source for the
     * refill INSERT/SELECT so the two column lists never drift.
     */
    private function columnList(): string
    {
        return implode(', ', [
            'id', 'collection_id', 'task_id', 'result_file_id', 'concept_id', '`count`',
            'concept_name', 'reported_domain_id', 'central_domain_id', 'domain_mismatch', 'domain_id',
        ]);
    }
}
