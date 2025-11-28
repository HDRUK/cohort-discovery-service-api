<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Distribution;
use App\Models\Collection;
use App\Models\Omop\Concept;
use App\Models\Omop\ConceptAncestor;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(
 *     name="OMOP",
 *     description="Endpoints for OMOP concept lookups and hierarchy exploration"
 * )
 */
class OmopController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getConcept($concept_id): JsonResponse
    {
        $concept = Concept::find($concept_id);
        if (!$concept) {
            return $this->NotFoundResponse();
        }
        $concept->load([
            'distributions:id,count,concept_id,collection_id',
            'distributions.collection:id,name'
        ]);

        return $this->OKResponse($concept);
    }

    public function getPeersAtLevel($concept_id): JsonResponse
    {
        $nup           = max(1, (int) request()->input('max_up', 1));
        $ndown           = max(1, (int) request()->input('max_down', 1));
        $standardOnly = request()->boolean('standard_only', false);
        $sameDomain   = request()->boolean('same_domain', false);
        $sameVocab    = request()->boolean('same_vocabulary', false);
        $slim    = request()->boolean('slim', true);
        $fullOmop    = request()->boolean('fullOmop', false);


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
                if (!$fullOmop) {
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
            $perPage = $this->resolvePerPage();
            $collectionPids = $request->input('collections');
            $domain = $request->input('domain');
            $includeAncestors = $request->boolean('include_ancestors', true);

            $codes = Distribution::query()
                ->when($collectionPids, function ($q, $pids) {
                    $collectionIds = Collection::whereIn('pid', $pids)->pluck('id');
                    $q->whereIn('collection_id', $collectionIds);
                })
                ->whereNotNull('concept_id')
                ->where('concept_id', '>', 0)
                ->when($domain, function ($q, $domain) {
                    $q->whereRaw('LOWER(category) = ?', [strtolower($domain)]);
                })
                ->when(
                    $includeAncestors,
                    function ($q) {
                        $q->with('children:concept_id,description,category');
                    }
                )
                ->select('name', 'concept_id', 'description', 'category')
                ->distinct()
                ->searchViaRequest($request->only(['concept_id', 'description']))
                ->paginate($perPage);

            return $this->OKResponse($codes);
        } catch (\Exception $e) {
            error_log($e->getMessage());
            return $this->ErrorResponse($e->getMessage());
        }
    }
}
