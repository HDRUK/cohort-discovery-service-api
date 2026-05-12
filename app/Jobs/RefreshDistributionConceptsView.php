<?php

namespace App\Jobs;

use App\Services\Activity\ActivityLogger;
use DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshDistributionConceptsView implements ShouldQueue
{
    use Queueable;

    private string $viewName = '';

    private string $distributionTable = '';

    private string $collectionTable = '';

    private string $conceptTable = '';

    private bool $onlyActive = false;

    /**
     * Create a new job instance.
     */
    public function __construct(bool $onlyActive = false)
    {
        $mysqlDb = config('database.connections.mysql.database');
        $omopDb  = config('database.connections.omop.database');

        $this->viewName         = "`{$mysqlDb}`.`distribution_concepts`";
        $this->distributionTable = "`{$mysqlDb}`.`distributions`";
        $this->collectionTable   = "`{$mysqlDb}`.`collections`";
        $this->conceptTable      = "`{$omopDb}`.`concept`";

        $this->onlyActive = $onlyActive;
    }

    /**
     * Execute the job.
     */
    public function searchConcepts(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $perPage          = $this->resolvePerPage(100, true);
            $page             = max(1, (int) $request->input('page', 1));
            $offset           = ($page - 1) * $perPage;
            $collectionPids   = (array) $request->input('collections', []);
            $domain           = $request->input('domain');
            $includeAncestors = $request->boolean('include_ancestors', true);
            $search           = $request->only(['concept_id', 'concept_name']);

            $bindings = [];
            $where    = ['d.concept_id IS NOT NULL', 'd.concept_id > 0'];

            $useCollectionsInSearch = Feature::active('query-builder-use-collections-in-search');

            if ($useCollectionsInSearch && !empty($collectionPids)) {
                $collectionIds = DB::table('collections')
                    ->whereIn('pid', $collectionPids)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                if (empty($collectionIds)) {
                    $paginator = new LengthAwarePaginator(
                        [],
                        0,
                        $perPage,
                        $page,
                        ['path' => $request->url(), 'query' => $request->query()]
                    );

                    $activityLogger->viewed('omop', null, [
                        'filters' => [
                            'page' => $page,
                            'per_page' => $perPage,
                            'collections' => $collectionPids,
                            'domain' => $domain,
                            'include_ancestors' => $includeAncestors,
                            'concept_id' => $search['concept_id'] ?? [],
                            'concept_name' => $search['concept_name'] ?? [],
                        ],
                        'feature_flags' => [
                            'query-builder-use-collections-in-search' => $useCollectionsInSearch,
                            'query-builder-use-stats-in-ordering' => Feature::active('query-builder-use-stats-in-ordering'),
                        ],
                        'result' => [
                            'total' => 0,
                            'returned' => 0,
                        ],
                    ]);

                    return $this->OKResponse($paginator);
                }

                $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
                $where[] = "d.collection_id IN ({$placeholders})";
                $bindings = array_merge($bindings, $collectionIds);
            }

            if ($domain) {
                $where[]    = 'd.category = ?';
                $bindings[] = strtolower($domain);
            }

            $searchConditions = [];
            $searchBindings   = [];

            foreach ((array) ($search['concept_id'] ?? []) as $term) {
                $term = trim((string) $term);

                if ($term === '' || !ctype_digit($term)) {
                    continue;
                }

                $searchConditions[] = 'd.concept_id = ?';
                $searchBindings[]   = (int) $term;
            }

            foreach ((array) ($search['concept_name'] ?? []) as $term) {
                $term = trim((string) $term);

                if ($term === '') {
                    continue;
                }

                $searchConditions[] = 'd.description LIKE ?';
                $searchBindings[]   = '%' . $term . '%';
            }

            if ($searchConditions) {
                $where[]  = '(' . implode(' OR ', $searchConditions) . ')';
                $bindings = array_merge($bindings, $searchBindings);
            }

            $whereClause = implode(' AND ', $where);

            $scoreClauses  = [];
            $scoreBindings = [];

            $useStatsInOrdering = Feature::active('query-builder-use-stats-in-ordering');

            foreach ((array) ($search['concept_name'] ?? []) as $term) {
                $term = trim((string) $term);

                if ($term === '') {
                    continue;
                }

                $scoreClauses[] = "
                CASE
                    WHEN LOWER(d.description) = LOWER(?) THEN 1000
                    WHEN LOWER(d.description) LIKE LOWER(?) THEN 500
                    WHEN LOWER(d.description) LIKE LOWER(?) THEN 100
                    ELSE 0
                END
            ";

                $scoreBindings[] = $term;             // exact match
                $scoreBindings[] = $term . '%';       // starts with
                $scoreBindings[] = '%' . $term . '%'; // contains
            }

            foreach ((array) ($search['concept_id'] ?? []) as $term) {
                $term = trim((string) $term);

                if ($term === '' || !ctype_digit($term)) {
                    continue;
                }

                $scoreClauses[] = "
                CASE
                    WHEN d.concept_id = ? THEN 1000
                    ELSE 0
                END
            ";

                $scoreBindings[] = (int) $term;
            }

            $scoreSql = $scoreClauses
                ? '(' . implode(' + ', $scoreClauses) . ')'
                : '0';

            $childrenJoin = $includeAncestors
                ? 'LEFT JOIN concept_ancestors ca ON ca.parent_concept_id = base.concept_id
               LEFT JOIN distributions dc ON dc.concept_id = ca.child_concept_id'
                : '';

            $childrenSelect = $includeAncestors
                ? ", JSON_ARRAYAGG(
                CASE WHEN dc.concept_id IS NOT NULL THEN
                    JSON_OBJECT(
                        'concept_id', dc.concept_id,
                        'name', dc.description,
                        'category', dc.category
                    )
                END
            ) AS children"
                : '';

            $orderBy = $useStatsInOrdering
                ? "
            ORDER BY
                base.match_score DESC,
                base.ncollections DESC,
                base.count DESC,
                CHAR_LENGTH(base.name) ASC,
                base.concept_id
        "
                : "
            ORDER BY
                base.match_score DESC,
                CHAR_LENGTH(base.name) ASC,
                base.concept_id
        ";

            $sql = "
            WITH base AS (
                SELECT
                    d.concept_id,
                    d.description AS name,
                    d.category,
                    {$scoreSql} AS match_score,
                    COUNT(DISTINCT d.collection_id) AS ncollections,
                    SUM(d.count) AS count
                FROM distributions d
                WHERE {$whereClause}
                GROUP BY d.concept_id, d.description, d.category
            ),
            total AS (
                SELECT COUNT(*) AS cnt FROM base
            )
            SELECT
                base.*,
                total.cnt
                {$childrenSelect}
            FROM base
            CROSS JOIN total
            {$childrenJoin}
            GROUP BY
                base.concept_id,
                base.name,
                base.category,
                base.match_score,
                base.ncollections,
                base.count,
                total.cnt
            {$orderBy}
            LIMIT ? OFFSET ?
        ";

            $finalBindings = array_merge($scoreBindings, $bindings, [$perPage, $offset]);

            $rows  = DB::select($sql, $finalBindings);
            $total = $rows[0]->cnt ?? 0;

            foreach ($rows as $row) {
                unset($row->cnt);

                if ($includeAncestors) {
                    $row->children = array_values(array_filter(
                        json_decode($row->children ?? '[]', true) ?? [],
                        fn ($c) => $c !== null
                    ));
                }
            }

            $paginator = new LengthAwarePaginator(
                $rows,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $activityLogger->viewed('omop', null, [
                'filters' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'collections' => $collectionPids,
                    'domain' => $domain,
                    'include_ancestors' => $includeAncestors,
                    'concept_id' => $search['concept_id'] ?? [],
                    'concept_name' => $search['concept_name'] ?? [],
                ],
                'feature_flags' => [
                    'query-builder-use-collections-in-search' => $useCollectionsInSearch,
                    'query-builder-use-stats-in-ordering' => $useStatsInOrdering,
                ],
                'result' => [
                    'total' => $total,
                    'returned' => count($rows),
                ],
            ]);

            return $this->OKResponse($paginator);
        } catch (\Exception $e) {
            error_log($e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
