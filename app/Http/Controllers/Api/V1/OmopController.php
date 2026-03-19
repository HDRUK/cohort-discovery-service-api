<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Omop\Concept;
use App\Models\Omop\ConceptAncestor;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="OMOP",
 *     description="Endpoints for OMOP concept lookups and hierarchy exploration"
 * )
 */
class OmopController extends Controller
{
    use HelperFunctions;
    use Responses;

    public function getConcept($concept_id): JsonResponse
    {
        $concept = Concept::find($concept_id);
        if (! $concept) {
            return $this->NotFoundResponse();
        }
        $concept->load([
            'distributions:id,count,concept_id,collection_id',
            'distributions.collection:id,name',
        ]);

        return $this->OKResponse($concept);
    }

    public function getPeersAtLevel($concept_id): JsonResponse
    {
        $nup = max(1, (int) request()->input('max_up', 1));
        $ndown = max(1, (int) request()->input('max_down', 1));
        $standardOnly = request()->boolean('standard_only', false);
        $sameDomain = request()->boolean('same_domain', false);
        $sameVocab = request()->boolean('same_vocabulary', false);
        $slim = request()->boolean('slim', true);
        $fullOmop = request()->boolean('fullOmop', false);

        $start = null;
        if ($sameDomain || $sameVocab) {
            $start = Concept::findOrFail($concept_id);
        }

        $ancestors = ConceptAncestor::where(['descendant_concept_id' => $concept_id])
            ->where('max_levels_of_separation', '<', $nup + 1)
            ->pluck('ancestor_concept_id');

        $desc = ConceptAncestor::whereIn('ancestor_concept_id', $ancestors)
            ->where('min_levels_of_separation', '>', 0)
            ->where('max_levels_of_separation', '<', $ndown + 1)
            ->when($standardOnly, function ($query) {
                $query->whereHas('descendant', function ($q) {
                    $q->where('standard_concept', 'S');
                });
            })
            ->when($sameDomain && $start, function ($q) use ($start) {
                $q->whereHas('descendant', fn ($c) => $c->where('domain_id', $start->domain_id));
            })
            ->when($sameVocab && $start, function ($q) use ($start) {
                $q->whereHas('descendant', fn ($c) => $c->where('vocabulary_id', $start->vocabulary_id));
            })
            ->with(['descendant' => function ($q) use ($slim, $fullOmop) {
                if (! $fullOmop) {
                    $q->inDistribution();
                }
                if ($slim) {
                    $q->select(['concept_id', 'concept_name']);
                }
                $q->distinct();
            }])
            ->get();

        $desc = $desc->pluck('descendant')
            ->filter()
            ->unique('concept_id')
            ->sortBy(function ($item) use ($concept_id) {
                return $item->concept_id == $concept_id ? 0 : 1;
            })
            ->values();

        return response()->json($desc);
    }

    public function searchConcepts(Request $request): JsonResponse
    {
        try {
            $perPage         = $this->resolvePerPage();
            $page            = max(1, (int) $request->input('page', 1));
            $offset          = ($page - 1) * $perPage;
            $collectionPids  = $request->input('collections');
            $domain          = $request->input('domain');
            $includeAncestors = $request->boolean('include_ancestors', true);
            $search          = $request->only(['concept_id', 'description']);

            $bindings = [];
            $where    = ['d.concept_id IS NOT NULL', 'd.concept_id > 0'];

            if ($collectionPids) {
                $placeholders = implode(',', array_fill(0, count($collectionPids), '?'));
                $where[]  = "d.collection_id IN (SELECT id FROM collections WHERE pid IN ({$placeholders}))";
                $bindings = array_merge($bindings, $collectionPids);
            }

            if ($domain) {
                $where[]    = 'd.category = ?';
                $bindings[] = strtolower($domain);
            }

            foreach ((array) ($search['concept_id'] ?? []) as $term) {
                $where[]    = 'd.concept_id LIKE ?';
                $bindings[] = '%' . $term . '%';
            }

            foreach ((array) ($search['description'] ?? []) as $term) {
                $where[]    = 'd.description LIKE ?';
                $bindings[] = '%' . $term . '%';
            }

            $whereClause = implode(' AND ', $where);

            $childrenJoin = $includeAncestors
                ? 'LEFT JOIN concept_ancestors ca ON ca.parent_concept_id = base.concept_id
                   LEFT JOIN distributions dc ON dc.concept_id = ca.child_concept_id'
                : '';

            $childrenSelect = $includeAncestors
                ? ", JSON_ARRAYAGG(
                       CASE WHEN dc.concept_id IS NOT NULL THEN
                           JSON_OBJECT(
                               'concept_id', dc.concept_id,
                               'description', dc.description,
                               'category', dc.category
                           )
                       END
                   ) AS children"
                : '';

            $sql = "
                WITH base AS (
                    SELECT DISTINCT d.name, d.concept_id, d.description, d.category
                    FROM distributions d
                    WHERE {$whereClause}
                ),
                total AS (SELECT COUNT(*) AS cnt FROM base)
                SELECT base.*, total.cnt {$childrenSelect}
                FROM base
                CROSS JOIN total
                {$childrenJoin}
                GROUP BY base.concept_id, base.name, base.description, base.category, total.cnt
                ORDER BY base.concept_id
                LIMIT ? OFFSET ?
            ";

            $bindings[] = $perPage;
            $bindings[] = $offset;

            $rows  = DB::select($sql, $bindings);
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

            return $this->OKResponse($paginator);
        } catch (\Exception $e) {
            error_log($e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
