<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Query;
use App\Services\Submitters\QuerySubmissionService;
use App\Traits\HelperFunctions;
use App\Traits\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @OA\Tag(
 *     name="Queries",
 *     description="Endpoints for managing saved queries and query downloads"
 * )
 */
class QueryController extends Controller
{
    use HelperFunctions;
    use Responses;
    use AuthorizesRequests;

    /**
     * @OA\Get(
     *     path="/api/v1/queries",
     *     summary="List queries for the authenticated user",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Results per page",
     *         required=false,
     *
     *         @OA\Schema(type="integer", example=25)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of queries",
     *
     *         @OA\JsonContent(
     *             type="object",
     *
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Query")),
     *             @OA\Property(property="meta", type="object")
     *         )
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();

            $queryBuilder = Query::searchViaRequest()
                ->filterViaRequest()
                ->applySorting('created_at', 'desc')
                ->with([
                    'tasks.collection.custodian',
                    'tasks.collection.latestDemographic',
                    'tasks.result',
                ])
                ->where('user_id', Auth::id())
                ->whereHas('tasks', function ($query) {
                    $query->where('task_type', TaskType::A);
                });

            $queries = (clone $queryBuilder)->get();
            foreach ($queries as $query) {
                $this->authorize('view', $query);
            }
            unset($queries);

            return $this->OKResponse($queryBuilder->paginate($perPage));
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@index - failed: '.
                json_encode($request->all()).' (exception: '.$e->getMessage().')');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/queries/{key}",
     *     summary="Get a single query by id or pid",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *
     *         @OA\Schema(type="string", example="1")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Query record",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::with([
                'tasks' => function ($taskQuery) {
                    $taskQuery
                        ->whereHas('collection', function ($collectionQuery) {
                            $collectionQuery
                                ->searchViaRequest();
                        })
                        // hack to allow applySorting to work on relationships
                        // - return to better optimise this?
                        // - I need to sort tasks (of a query) based on tasks.collection.name
                        // New requirement to also maybe default sort by the result count
                        // - doing this on the FE for now
                        ->leftJoin('collections as collection', 'collection.id', '=', 'tasks.collection_id')
                        ->select('tasks.*')
                        ->with([
                            'collection.latestDemographic',
                            'collection.custodian',
                            'result',
                            'latestRun'
                        ])
                        ->applySorting();
                },
            ])
                ->when(
                    ctype_digit($key),
                    fn ($q) => $q->where('id', $key),
                    fn ($q) => $q->where('pid', $key)
                )
                ->firstOrFail();

            $this->authorize('view', $query);

            return $this->OKResponse($query);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@show - failed: '.json_encode($validated));

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/queries",
     *     summary="Create and submit a new query",
     *     tags={"Queries"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Created and submitted query result",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *
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
            \Log::error('QueryController@store - failed: '.json_encode($validated));

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/queries/{key}",
     *     summary="Update an existing query (by id or pid)",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *
     *         @OA\Schema(type="string", example="col_abc123")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(ref="#/components/schemas/Query")
     *     ),
     *
     *     @OA\Response(response=200, description="Updated query", @OA\JsonContent(ref="#/components/schemas/Query")),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::when(
                ctype_digit($key),
                fn ($q) => $q->where('id', $key),
                fn ($q) => $q->where('pid', $key)
            )
                ->firstOrFail();

            $this->authorize('update', $query);

            if ($query->update($validated)) {
                return $this->OKResponse($query);
            }

            return $this->ErrorResponse();
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@update - failed: '.
                json_encode($validated).' (exception: '.
                $e->getMessage().')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/queries/{key}",
     *     summary="Delete a query by id or pid",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="key",
     *         in="path",
     *         description="Database id or public pid of the query",
     *         required=true,
     *
     *         @OA\Schema(type="string", example="1")
     *     ),
     *
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $query = Query::when(
                ctype_digit($key),
                fn ($q) => $q->where('id', $key),
                fn ($q) => $q->where('pid', $key)
            )
                ->firstOrFail();

            $this->authorize('delete', $query);

            if ($query->delete()) {
                return $this->OKResponse([]);
            }

            return $this->ErrorResponse();
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@destroy/'.$validated['id'].' - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->NotFoundResponse();
        }
    }

    public function destroyBulk(Request $request): JsonResponse
    {
        $input = $request->validate(app(Query::class)->getValidationRules('deletebulk'));

        try {
            Query::whereIn('pid', $input['keys'])->delete();
            return $this->OKResponse([]);

        } catch (\Throwable $e) {
            \Log::error('QueryController@destroyBulk - failed: '.
                json_encode($input).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse();
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/queries/{pid}/download",
     *     summary="Download query results for a saved query",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         description="Public pid of the saved query",
     *         required=true,
     *
     *         @OA\Schema(type="string", example="qry_abc123")
     *     ),
     *
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         description="Output format (csv|json)",
     *         required=false,
     *
     *         @OA\Schema(type="string", example="csv")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Streamed file download (binary)",
     *
     *         @OA\MediaType(
     *             mediaType="application/octet-stream"
     *         )
     *     ),
     *
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function download(Request $request, string $pid, string $format = 'csv'): StreamedResponse|BinaryFileResponse|JsonResponse
    {
        try {
            $queryBuilder = Query::searchViaRequest()
                ->filterViaRequest()
                ->with([
                    'tasks.collection.latestDemographic',
                    'tasks.result',
                ])
                ->where('pid', $pid)
                ->orderBy('created_at', 'desc');

            $queries = (clone $queryBuilder)->get();
            foreach ($queries as $query) {
                $this->authorize('download', $query);
            }
            unset($queries);

            return $queryBuilder->download($format);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@download/'.$format.' - failed'.
                ' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse();
        }
    }

    public function duplicateAndReRun(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();
        $query = null;
        $data = [];

        try {
            $query = Query::with('tasks.collection')->when(
                ctype_digit($key),
                fn ($q) => $q->where('id', $key),
                fn ($q) => $q->where('pid', $key)
            )
                ->first();

            $data['name'] = $query->name .= ' - ReRun ('.now()->format('Y-m-d H:i:s').')';
            $data['task_type'] = TaskType::A;
            $data['definition'] = $query->definition;
            $data['collection_filter'] = $query->tasks->pluck('collection.pid')->toArray();

            $result = app(QuerySubmissionService::class)
                ->handle($data, Auth::id());

            return $this->OKResponse($result);
        } catch (\Throwable $e) {
            \Log::error('QueryController@duplicateAndReRun/'.$validated['key'].' - failed: '.
                json_encode($validated).' and duplicate: '.json_encode($query).' (exception: '.$e->getMessage().')');

            return $this->NotFoundResponse();
        }
    }
}
