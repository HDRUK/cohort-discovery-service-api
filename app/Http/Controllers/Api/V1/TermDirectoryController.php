<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DistributionConcept;
use App\Services\Activity\ActivityLogger;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="TermDirectory",
 *     description="Lists OMOP concept availability across the federation, backing the Term Directory page."
 * )
 */
class TermDirectoryController extends Controller
{
    use HelperFunctions;
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/term-directory",
     *     summary="List OMOP concepts (searchable, filterable, sortable, paginated)",
     *     tags={"TermDirectory"},
     *     @OA\Parameter(
     *         name="concept_name",
     *         in="query",
     *         required=false,
     *         description="Search by concept id or name",
     *         @OA\Schema(type="string", example="diabetes")
     *     ),
     *     @OA\Parameter(
     *         name="domain_id",
     *         in="query",
     *         required=false,
     *         description="Filter by OMOP domain",
     *         @OA\Schema(type="string", example="Condition")
     *     ),
     *     @OA\Parameter(
     *         name="sort",
     *         in="query",
     *         required=false,
     *         description="e.g. count:desc",
     *         @OA\Schema(type="string", example="count:desc")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=25)
     *      ),
     *     @OA\Response(
     *      response=200,
     *      description="Paginated list of concepts")
     * )
     */
    public function index(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();

        DistributionConcept::searchViaRequest()   // WHERE (concept_name LIKE '%…%' OR …)
            ->filterViaRequest()                  // AND (domain_id = '…')
            ->applySorting('count', 'desc')       // ORDER BY count DESC
            ->paginate($perPage);                 // LIMIT/OFFSET — and RUNS it

            $activityLogger->viewed('term_directory', null, [
                'filters' => $request->query(),
                'result' => [
                    'total' => $concepts->total(),
                ],
            ]);

            return $this->OKResponse($concepts);
        } catch (\Throwable $e) {
            Log::error('TermDirectoryController@index - failed: ' . $e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
