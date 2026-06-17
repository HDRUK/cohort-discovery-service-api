<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DistributionConcept;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DistributionConceptController extends Controller
{
    use HelperFunctions;
    use Responses;

    public function domains(): JsonResponse
    {
        $domains = DistributionConcept::query()
            ->distinct()
            ->whereNotNull('domain_id')
            ->orderBy('domain_id')
            ->pluck('domain_id');

        return $this->OKResponse($domains);
    }

    public function index(Request $request): JsonResponse
    {
        $domain  = $request->input('domain');
        $perPage = $this->resolvePerPage(100);

        $concepts = DistributionConcept::query()
            ->when($domain, fn ($q) => $q->where('domain_id', $domain))
            ->orderBy('concept_name')
            ->paginate($perPage);

        return $this->OKResponse($concepts);
    }
}
