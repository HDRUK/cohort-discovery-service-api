<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Omop\Concept;
use App\Models\Omop\ConceptAncestor;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OmopController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getPeersAtLevel($concept_id)
    {
        $nup           = max(1, (int) request()->input('max_up', 1));
        $ndown           = max(1, (int) request()->input('max_down', 1));
        $standardOnly = request()->boolean('standard_only', false);
        $sameDomain   = request()->boolean('same_domain', false);
        $sameVocab    = request()->boolean('same_vocabulary', false);

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
                $q->whereHas('descendant', fn($c) => $c->where('domain_id', $start->domain_id));
            })
            ->when($sameVocab && $start, function ($q) use ($start) {
                $q->whereHas('descendant', fn($c) => $c->where('vocabulary_id', $start->vocabulary_id));
            })
            ->with('descendant')
            ->get();


        return response()->json($desc);
    }
}
