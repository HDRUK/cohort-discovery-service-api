<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Activity\ActivityLogger;
use App\Services\TermDirectory\TermDirectoryService;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @OA\Tag(
 *     name="TermDirectory",
 *     description="Lists OMOP concept availability across the federation, backing the Term Directory page."
 * )
 * @OA\Tag(
 *     name="TermDirectoryDownload",
 *     description="Exports all available concepts from the term directory as CSV"
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
     *         description="Filter by the effective OMOP domain (collection-reported by default)",
     *         @OA\Schema(type="string", example="Condition")
     *     ),
     *     @OA\Parameter(
     *         name="domain_id__in",
     *         in="query",
     *         required=false,
     *         description="Filter by any of several OMOP domains (comma-separated)",
     *         @OA\Schema(type="string", example="Gender,Race,Ethnicity")
     *     ),
     *     @OA\Parameter(
     *         name="collection_pid[]",
     *         in="query",
     *         required=false,
     *         description="Only include concepts from these collections (public pids). Pids outside the user's visible collections are ignored.",
     *         @OA\Schema(type="array", @OA\Items(type="string", example="9a8b7c6d-0000-0000-0000-000000000000"))
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
    public function index(Request $request, ActivityLogger $activityLogger, TermDirectoryService $termDirectory): JsonResponse
    {
        try {
            $concepts = $termDirectory->search($request, $this->resolvePerPage());

            $activityLogger->viewed('term_directory', null, [
                'filters' => $request->query(),
                'result' => ['total' => $concepts->total()],
            ]);

            return $this->OKResponse($concepts);
        } catch (\Throwable $e) {
            Log::error('TermDirectoryController@index - failed: ' . $e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/term-directory/download",
     *     summary="Export all available term directory concepts as CSV",
     *     tags={"TermDirectoryDownload"},
     *      @OA\Response(
     *      response=200,
     *      description="CSV file of concepts")
     */
    public function download(
        Request $request,
        ActivityLogger $activityLogger,
        TermDirectoryService $termDirectory,
    ): StreamedResponse | JsonResponse {
        try {

            $concepts = $termDirectory->search($request, 100);
            $lastPage = $concepts->lastPage();
            $allConcepts = $concepts->items();

            if ($lastPage > 1) {
                for ($pageNum = 2; $pageNum <= $lastPage; $pageNum++) {
                    $concepts = $termDirectory->search($request->merge(['page' => $pageNum]), 100);
                    $allConcepts = array_merge($allConcepts, $concepts->items());
                }
            }

            // need to figure out what type of log to use
            // $activityLogger->viewed('term_directory', null, [
            //     'filters' => $request->query(),
            //     'result' => ['total' => $concepts->total()],
            // ]);

            // \Log::debug(count($allConcepts));

            $response = new StreamedResponse(
                function () use ($allConcepts) {
                    // Open output stream
                    $handle = fopen('php://output', 'w');

                    $headerRow = [
                        'Concept ID',
                        'Term Name',
                        'Domain',
                        'Count',
                        'Associated Collections',
                    ];

                    // Add CSV headers
                    fputcsv($handle, $headerRow);
                    // add the given number of rows to the file.
                    foreach ($allConcepts as $concept) {
                        $row = [
                            $concept['concept_id'] !== null ? $concept['concept_id'] : '',
                            $concept['concept_name'] !== null ? $concept['concept_name'] : '',
                            $concept['domain_id'] !== null ? $concept['domain_id'] : '',
                            $concept['count'] !== null ? $concept['count'] : '',
                            $concept['ncollections'] !== null ? $concept['ncollections'] : '',
                        ];
                        fputcsv($handle, $row);
                    }

                    // Close the output stream
                    fclose($handle);
                }
            );

            $response->headers->set('Content-Type', 'text/csv');
            $filename = 'term-directory-exported.csv';
            $response->headers->set('Content-Disposition', 'attachment;filename="' . $filename . '"');
            $response->headers->set('Cache-Control', 'max-age=0');


            // return $allConcepts;
            return $response;
        } catch (\Throwable $e) {
            \Log::error('TermDirectoryController@download/ - failed' .
                ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse();
        }
    }
}
