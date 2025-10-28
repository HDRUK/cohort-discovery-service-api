<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Http\Requests\ModelBackedRequest;
use App\Enums\TaskType;
use App\Models\Query;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use App\Services\Submitters\QuerySubmissionService;
use App\Http\Controllers\Controller;

/**
 * @OA\Tag(
 *     name="Queries",
 *     description="Endpoints for managing saved queries and query downloads"
 * )
 */
class QueryController extends Controller
{
    use Responses;
    use HelperFunctions;

    /**
     * @OA\Get(
     *     path="/api/v1/queries",
     *     summary="List queries for the authenticated user",
     *     tags={"Queries"},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Results per page",
     *         required=false,
     *         @OA\Schema(type="integer", example=25)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of queries",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Query")),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        $perPage = $this->resolvePerPage();

        $queries = Query::searchViaRequest()
            ->filterViaRequest()
            ->with([
            'tasks.collection.size',
            'tasks.result'
        ])
            ->where('user_id', Auth::id())
            ->whereHas('tasks', function ($query) {
                $query->where('task_type', TaskType::A);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        return $this->OKResponse($queries);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/queries/{key}",
     *     summary="Get a single query by id or pid",
     *     tags={"Queries"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *         @OA\Schema(type="string", example="1")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Query record",
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::with(['tasks.collection.size', 'tasks.result'])
                ->where('id', $key)
                ->orWhere('pid', $key)
                ->firstOrFail();

            if (Gate::denies('view', $query)) {
                return  $this->ForbiddenResponse();
            }

            return $this->OKResponse($query);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/queries",
     *     summary="Create and submit a new query",
     *     tags={"Queries"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Created and submitted query result",
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = app(QuerySubmissionService::class)
                ->handle($validated, Auth::id());

            return $this->CreatedResponse($result);
        } catch (\Throwable $e) {
            \Log::error('QueryController@store - failed: ' . json_encode($validated));
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/queries/{key}",
     *     summary="Update an existing query (by id or pid)",
     *     tags={"Queries"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *         @OA\Schema(type="string", example="col_abc123")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *     @OA\Response(response=200, description="Updated query", @OA\JsonContent(ref="#/components/schemas/Query")),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::where('id', $key)
                ->orWhere('pid', $key)
                ->firstOrFail();
            if ($query->update($validated)) {
                return $this->OKResponse($query);
            }

            return $this->ErrorResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@update - failed: ' .
                json_encode($validated) . ' (exception: ' .
                $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/queries/{key}",
     *     summary="Delete a query by id or pid",
     *     tags={"Queries"},
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *         @OA\Schema(type="string", example="1")
     *     ),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::where('id', $key)
                        ->orWhere('pid', $key)
                        ->firstOrFail();
            if ($query->delete()) {
                return $this->OKResponse([]);
            }

            return $this->ErrorResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@destroy/' . $validated['id'] . ' - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/queries/{pid}/download",
     *     summary="Download query results for a saved query",
     *     tags={"Queries"},
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         description="Public pid of the saved query",
     *         required=true,
     *         @OA\Schema(type="string", example="qry_abc123")
     *     ),
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         description="Output format (csv|json)",
     *         required=false,
     *         @OA\Schema(type="string", example="csv")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Streamed file download (binary)",
     *         @OA\MediaType(
     *             mediaType="application/octet-stream"
     *         )
     *     ),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function download(Request $request, string $pid, string $format = 'csv'): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        try {
            return Query::searchViaRequest()
                ->filterViaRequest()
                ->with([
                    'tasks.collection.size',
                    'tasks.result'
                ])
                ->where('pid', $pid)
                ->orderBy('created_at', 'desc')
                ->download($format);
        } catch (\Throwable $e) {
            \Log::error('QueryController@download/' . $format . ' - failed' .
                ' (exception: ' . $e->getMessage() . ')');
            return $this->ErrorResponse();
        }
    }

    public function duplicateAndReRun(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();
        $query = null;

        try {
            $query = Query::where('id', $key)
                ->orWhere('pid', $key)
                ->first()
                ->toArray();

            // We don't save this as we just need the reference for the duplicate.
            $query['name'] .= ' - ReRun (' . now()->format('Ymd') . ')';
            // Force a rerun of query type - we can safely assume this as users
            // cannot create a distribution query
            $query['task_type'] = TaskType::A;

            $result = app(QuerySubmissionService::class)
                ->handle($query, Auth::id());

            return $this->OKResponse($result);
        } catch (\Throwable $e) {
            \Log::error('QueryController@duplicateAndReRun/' . $validated['key'] . ' - failed: ' .
                json_encode($validated) . ' and duplicate: ' . json_encode($query) . ' (exception: ' . $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }
    }
}
