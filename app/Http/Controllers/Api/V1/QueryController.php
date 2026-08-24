<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Collection;
use App\Models\Query;
use App\Services\Activity\ActivityLogger;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;
use App\Services\Submitters\QuerySubmissionService;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Pennant\Feature;
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
    public function index(ModelBackedRequest $request, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();

            $queryBuilder = Query::searchViaRequest()
                ->filterViaRequest()
                ->applySorting('created_at', 'desc')
                ->with([
                    'tasks.collection.custodian.network',
                    'tasks.result',
                ])
                ->where('user_id', Auth::id())
                ->whereHas('tasks', function ($query) {
                    $query->where('task_type', TaskType::A);
                });

            $paginatedQueries = $queryBuilder->paginate($perPage);

            $activityLogger->viewed('queries', null, [
                'filters' => $request->all(),
                'result' => [
                    'total' => $paginatedQueries->total(),
                    'returned' => $paginatedQueries->count(),
                    'page' => $paginatedQueries->currentPage(),
                    'per_page' => $paginatedQueries->perPage(),
                ],
            ]);

            return $this->OKResponse($paginatedQueries);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@index - failed: ' .
                json_encode($request->all()) . ' (exception: ' . $e->getMessage() . ')');
            return $this->ErrorResponse($e->getMessage());
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
    public function show(
        ModelBackedRequest $request,
        ActivityLogger $activityLogger,
        mixed $key = null
    ): JsonResponse {
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
                            'collection.custodian.network',
                            'result',
                            'latestRun'
                        ])
                        ->applySorting();
                },
            ])
                ->when(
                    ctype_digit($key),
                    fn($q) => $q->where('id', $key),
                    fn($q) => $q->where('pid', $key)
                )
                ->firstOrFail();

            $this->authorize('view', $query);

            $activityLogger->viewed('queries', $query);

            return $this->OKResponse($query);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@show - failed: ' . json_encode($validated));

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
    public function store(ModelBackedRequest $request, ActivityLogger $activityLogger): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = app(QuerySubmissionService::class)
                ->handle($validated, Auth::id());

            $query = Query::where('pid', $result['query_pid'] ?? null)->first();

            if ($query) {
                $activityLogger->created('queries', $query, [
                    'tasks' => [
                        'task_count' => $result['task_count'] ?? null,
                        'task_pids' => $result['task_pids'] ?? [],
                    ],
                ]);
            }

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
    public function update(
        ModelBackedRequest $request,
        ActivityLogger $activityLogger,
        mixed $key = null,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $query = Query::when(
                ctype_digit($key),
                fn($q) => $q->where('id', $key),
                fn($q) => $q->where('pid', $key)
            )
                ->firstOrFail();

            $this->authorize('update', $query);

            $before = $query->only(array_keys($validated));
            if ($query->update($validated)) {
                $query->refresh();

                $activityLogger->updated(
                    'queries',
                    $query,
                    $before,
                    $query->only(array_keys($validated))
                );

                return $this->OKResponse($query);
            }

            return $this->ErrorResponse();
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
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
    public function destroy(
        ModelBackedRequest $request,
        ActivityLogger $activityLogger,
        mixed $key = null,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $query = Query::when(
                ctype_digit($key),
                fn($q) => $q->where('id', $key),
                fn($q) => $q->where('pid', $key)
            )
                ->firstOrFail();

            $this->authorize('delete', $query);

            if ($query->delete()) {
                $activityLogger->deleted('queries', $query);

                return $this->OKResponse([]);
            }

            return $this->ErrorResponse();
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@destroy/' . $validated['id'] . ' - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');

            return $this->NotFoundResponse();
        }
    }

    public function destroyBulk(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        $input = $request->validate(app(Query::class)->getValidationRules('deletebulk'));

        try {
            Query::whereIn('pid', $input['keys'])->delete();

            $activityLogger->custom('queries', 'deleted', null, [
                'query_pids' => $input['keys'],
                'result' => [
                    'total' => count($input['keys']),
                ],
            ]);

            return $this->OKResponse([]);
        } catch (\Throwable $e) {
            \Log::error('QueryController@destroyBulk - failed: ' .
                json_encode($input) . ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/queries/translate/{context}",
     *     summary="Translate a raw query definition into a specific query context format, without submitting it",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="context",
     *         in="path",
     *         description="Query context slug",
     *         required=true,
     *
     *         @OA\Schema(type="string", enum={"bunny", "beacon"}, example="bunny")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"definition"},
     *
     *             @OA\Property(
     *                 property="definition",
     *                 type="array",
     *                 description="Structured query definition (same shape as Query::definition)",
     *
     *                 @OA\Items(type="object")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Translated query context"),
     *     @OA\Response(response=422, description="Validation error, or unsupported context"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function translate(Request $request, QueryContextManager $contextManager, string $context): JsonResponse
    {
        $contextType = QueryContextType::tryFrom($context);
        if (! $contextType) {
            return $this->ValidationErrorResponse([
                'context' => [sprintf(
                    'Unsupported query context "%s". Supported: %s',
                    $context,
                    implode(', ', array_column(QueryContextType::cases(), 'value'))
                )],
            ]);
        }

        $validated = $request->validate([
            'definition' => 'required|array',
        ]);

        try {
            $translated = $contextManager->handle(
                $validated['definition'],
                $contextType,
                Feature::active('flatten-nested-groups')
            );

            return $this->OKResponse($translated);
        } catch (\Throwable $e) {
            \Log::error('QueryController@translate/' . $context . ' - failed: ' . json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse($e->getMessage());
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
    public function download(
        Request $request,
        ActivityLogger $activityLogger,
        string $pid,
        string $format = 'csv'
    ): StreamedResponse|BinaryFileResponse|JsonResponse {
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

            $activityLogger->custom('queries', 'downloaded', null, [
                'filters' => $request->query(),
                'download' => [
                    'format' => $format,
                    'query_pids' => $queries->pluck('pid')->values()->all(),
                ],
            ]);

            unset($queries);

            return $queryBuilder->download($format);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@download/' . $format . ' - failed' .
                ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse();
        }
    }

    public function duplicateAndReRun(
        ModelBackedRequest $request,
        ActivityLogger $activityLogger,
        mixed $key = null
    ): JsonResponse {
        $validated = $request->validated();
        $query = null;
        $data = [];

        try {
            $query = Query::with('tasks.collection')->when(
                ctype_digit($key),
                fn($q) => $q->where('id', $key),
                fn($q) => $q->where('pid', $key)
            )
                ->first();

            $data['name'] = $query->name .= ' - ReRun (' . now()->format('Y-m-d H:i:s') . ')';
            $data['task_type'] = TaskType::A;
            $data['definition'] = $query->definition;
            $data['collection_filter'] = $query->tasks->pluck('collection.pid')->toArray();

            $result = app(QuerySubmissionService::class)
                ->handle($data, Auth::id());

            $activityLogger->custom('queries', 'cloned', $query, [
                'tasks' => [
                    'task_count' => $result['task_count'] ?? null,
                    'task_pids' => $result['task_pids'] ?? [],
                ],
            ]);

            return $this->OKResponse($result);
        } catch (\Throwable $e) {
            \Log::error('QueryController@duplicateAndReRun/' . $validated['key'] . ' - failed: ' .
                json_encode($validated) . ' and duplicate: ' . json_encode($query) . ' (exception: ' . $e->getMessage() . ')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/queries/{pid}/click-through",
     *     summary="Record that a user followed a dataset link from a query's results",
     *     description="Logs an anonymous click-through event against the query pid.
     *         No user is recorded on the entry - see the DP-946 DPIA note.",
     *     tags={"Queries"},
     *
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         required=true,
     *         description="Query pid",
     *
     *         @OA\Schema(type="string", format="uuid")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             type="object",
     *             required={"collection_pid"},
     *
     *             @OA\Property(property="collection_pid", type="string", example="col_abc123")
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Click-through logged"),
     *     @OA\Response(response=403, description="Query does not belong to this user"),
     *     @OA\Response(response=404, description="Query not found, or collection is not part of the query"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function clickThrough(
        Request $request,
        ActivityLogger $activityLogger,
        string $pid
    ): JsonResponse {
        // Outside the try: a ValidationException must reach Laravel's handler
        // as a 422 rather than being swallowed by the catch-all below.
        $validated = $request->validate([
            'collection_pid' => 'required|string',
        ]);

        try {
            $query = Query::where('pid', $pid)->firstOrFail();

            // Guard 1: the query must be the caller's own (QueryPolicy::access also lets admins through).
            $this->authorize('view', $query);

            // Guard 2: collection_pid comes from the browser and can be edited, so only accept
            // one that genuinely has a Task for this query. Otherwise the click counts are forgeable.
            $collection = Collection::whereHas(
                'tasks',
                fn($q) => $q->where('query_id', $query->id)
            )->where('pid', $validated['collection_pid'])->first();

            if (! $collection) {
                return $this->NotFoundResponse();
            }

            $activityLogger->custom('queries', 'clicked_through', $collection, [
                'query_pid' => $query->pid,
                'collection_pid' => $collection->pid,
                'destination_url' => $collection->url,
            ], anonymous: Feature::active('dataset-click-through-anonymous-logging'));

            return $this->OKResponse(null);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (ModelNotFoundException $e) {
            return $this->NotFoundResponse();
        } catch (\Throwable $e) {
            \Log::error('QueryController@clickThrough/' . $pid . ' - failed' .
                ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse();
        }
    }
}
