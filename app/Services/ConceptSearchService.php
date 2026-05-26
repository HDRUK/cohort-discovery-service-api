<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class ConceptSearchService
{
    private const SEARCH_TEXT_SEPARATORS = ['-', '/', '_'];

    /**
     * This service is the shared concept search implementation for both HTTP concept search and internal query parsing.
     *
     * The inputs are explicit so controllers and application services can reuse the same ranking and filtering logic without making an HTTP request back into this API.
     */
    public function search(
        array $conceptNames = [],
        array $conceptIds = [],
        ?string $domain = null,
        array $collectionPids = [],
        bool $includeAncestors = true,
        int $page = 1,
        int $perPage = 100
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $bindings = [];
        $where = ['d.concept_id IS NOT NULL', 'd.concept_id > 0'];

        if (Feature::active('query-builder-use-collections-in-search') && ! empty($collectionPids)) {
            $collectionIds = DB::table('collections')
                ->whereIn('pid', $collectionPids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (empty($collectionIds)) {
                $where[] = '1 = 0';
            } else {
                $placeholders = implode(',', array_fill(0, count($collectionIds), '?'));
                $where[] = "d.collection_id IN ({$placeholders})";
                $bindings = array_merge($bindings, $collectionIds);
            }
        }

        if ($domain) {
            $where[] = 'd.category = ?';
            $bindings[] = strtolower($domain);
        }

        $searchConditions = [];
        $searchBindings = [];
        $normalisedDescriptionSql = $this->normalisedDescriptionSql();

        foreach ($conceptIds as $term) {
            $term = trim((string) $term);
            if ($term === '' || ! ctype_digit($term)) {
                continue;
            }

            $searchConditions[] = 'd.concept_id = ?';
            $searchBindings[] = (int) $term;
        }

        foreach ($conceptNames as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }

            $normalisedTerm = $this->normaliseSearchText($term);

            $searchConditions[] = "(d.description LIKE ? OR {$normalisedDescriptionSql} LIKE ?)";
            $searchBindings[] = '%' . $term . '%';
            $searchBindings[] = '%' . $normalisedTerm . '%';
        }

        if ($searchConditions) {
            $where[] = '(' . implode(' OR ', $searchConditions) . ')';
            $bindings = array_merge($bindings, $searchBindings);
        }

        $whereClause = implode(' AND ', $where);

        $scoreClauses = [];
        $scoreBindings = [];

        foreach ($conceptNames as $term) {
            $term = trim((string) $term);

            if ($term === '') {
                continue;
            }

            $normalisedTerm = $this->normaliseSearchText($term);

            $scoreClauses[] = "
                CASE
                    WHEN LOWER(d.description) = LOWER(?) THEN 1000
                    WHEN LOWER(d.description) LIKE LOWER(?) THEN 500
                    WHEN LOWER(d.description) LIKE LOWER(?) THEN 100
                    WHEN {$normalisedDescriptionSql} = ? THEN 1000
                    WHEN {$normalisedDescriptionSql} LIKE ? THEN 500
                    WHEN {$normalisedDescriptionSql} LIKE ? THEN 100
                    ELSE 0
                END
            ";

            $scoreBindings[] = $term;
            $scoreBindings[] = '%' . $term . '%';
            $scoreBindings[] = $term . '%';
            $scoreBindings[] = $normalisedTerm;
            $scoreBindings[] = '%' . $normalisedTerm . '%';
            $scoreBindings[] = $normalisedTerm . '%';
        }

        foreach ($conceptIds as $term) {
            $term = trim((string) $term);
            if ($term === '' || ! ctype_digit($term)) {
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

        $orderBy = Feature::active('query-builder-use-stats-in-ordering')
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

        $rows = DB::select($sql, array_merge($scoreBindings, $bindings, [$perPage, $offset]));
        $total = $rows[0]->cnt ?? 0;

        foreach ($rows as $row) {
            unset($row->cnt);

            if ($includeAncestors) {
                $row->children = array_values(array_filter(
                    json_decode($row->children ?? '[]', true) ?? [],
                    fn ($child) => $child !== null
                ));
            }
        }

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    public function resolveTerm(string $term, ?string $domain = null, int $limit = 10): array
    {
        // Internal query parsing uses this method when it needs ranked concepts without the full paginated HTTP response shape.
        $paginator = $this->search(
            conceptNames: [$term],
            domain: $domain,
            includeAncestors: false,
            perPage: $limit
        );

        // The returned shape contains only the fields needed to turn search results back into rule-builder candidates.
        return array_map(
            fn ($row) => [
                'concept_id' => $row->concept_id,
                'name' => $row->name,
                'description' => $row->name,
                'category' => $row->category,
                'children' => [],
                'ncollections' => $row->ncollections ?? 0,
                'all_synthetic' => $row->all_synthetic ?? 0,
                'match_score' => $row->match_score ?? 0,
            ],
            $paginator->items()
        );
    }

    private function normaliseSearchText(string $term): string
    {
        // Common separators are treated as spaces so equivalent searches such as "covid 19", "covid-19", "covid/19", and "covid_19" can match the same concept.
        $normalised = str_replace(
            self::SEARCH_TEXT_SEPARATORS,
            ' ',
            strtolower($term)
        );

        return trim(preg_replace('/\s+/', ' ', $normalised) ?? '');
    }

    private function normalisedDescriptionSql(): string
    {
        return "LOWER(REPLACE(REPLACE(REPLACE(d.description, '-', ' '), '/', ' '), '_', ' '))";
    }
}
