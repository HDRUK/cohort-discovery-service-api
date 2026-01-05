<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CollectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Task;
use App\Models\Workgroup;
use App\Models\WorkgroupHasCollection;
use App\Services\CollectionStateService;
use App\Services\QueryContext\QueryContextType;
use App\Traits\HelperFunctions;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @OA\Tag(
 *     name="Collections",
 *     description="API Endpoints for managing Collections"
 * )
 */
class CollectionController extends Controller
{
    use HelperFunctions;
    use Responses;

    protected CollectionStateService $stateService;

    public function __construct(CollectionStateService $stateService)
    {
        $this->stateService = $stateService;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collections",
     *     summary="Get all collections",
     *     tags={"Collections"},
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
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of collections",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Collection"))
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        $collections = Collection::with([
            'demographics',
            'custodian.network',
            'modelState.state',
        ])
            ->searchViaRequest()
            ->filterViaRequest()
            ->applySorting()
            ->get();

        return $this->OKResponse($collections);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/admin/collections",
     *     summary="Get all collections, paginated for global admin",
     *     tags={"Collections"},
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
     *     ),
     *     @OA\Parameter(
     *         name="state",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="active")
     *     ),
     *     @OA\Parameter(
     *         name="workgroup_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="1")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of collections for admin",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Collection"))
     *     )
     * )
     */
    public function indexForAdmin(Request $request): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();

            $collections = Collection::query()
                ->with([
                    'host',
                    'custodian',
                    'config',
                    'modelState.state',
                    'latestDemographic.task',
                    'latestConcept.task',
                    'latestDemographicTask',
                    'latestConceptTask',
                    'workgroups',
                ])
                ->withCount(['concepts as n_concepts'])
                ->when($request->filled('state'), function ($q) use ($request) {
                    if ($request->state !== 'all') {
                        $q->whereRelation('modelState.state', 'states.slug', strtolower($request->state));
                    }
                })
                ->when($request->filled('workgroup_id'), function ($q) use ($request) {
                    $q->whereRelation('workgroups', 'workgroups.id', $request->workgroup_id);
                })
                ->searchViaRequest()
                ->filterViaRequest()
                ->applySorting()
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@indexForAdmin - failed: '.
                $e->getMessage());

            throw $e;
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collections/{id}",
     *     summary="Get a collection by ID",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection found",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(response=404, description="Collection not found")
     * )
     */
    public function show(ModelBackedRequest $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validated();

        try {
            $collection = Collection::with([
                'demographics',
                'custodian',
                'modelState.state',
                'workgroups',
            ])->findOrFail($validated['id']);

            return $this->OKResponse($collection);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/collections",
     *     summary="Create a new collection",
     *     tags={"Collections"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Collection created",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collection = Collection::create($validated);

            return $this->CreatedResponse($collection);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@store - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/collections/{id}",
     *     summary="Update a collection by ID",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection updated",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(response=404, description="Collection not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(ModelBackedRequest $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validated();

        try {
            $collection = Collection::with(['host','config','custodian'])->findOrFail($validated['id']);
            if ($collection->update($validated)) {
                return $this->OKResponse($collection);
            }
        } catch (\Throwable $e) {
            \Log::error('CollectionController@update - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->NotFoundResponse();
        }

        return $this->ErrorResponse();
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/collections/{id}",
     *     summary="Delete a collection by ID",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(response=200, description="Collection deleted"),
     *     @OA\Response(response=404, description="Collection not found")
     * )
     */
    public function destroy(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['id']);
            if ($collection->delete()) {
                return $this->OKResponse([]);
            }
        } catch (\Throwable $e) {
            \Log::error('CollectionController@destroy - failed: '.
                $e->getMessage());

            return $this->NotFoundResponse();
        }

        return $this->ErrorResponse();
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collections/pid/{pid}",
     *     summary="Get a collection by public PID",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string", example="col_abc123")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection found",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(response=404, description="Collection not found")
     * )
     */
    public function getCollection($pid): JsonResponse
    {
        $collection = Collection::where('pid', $pid)
            ->with('latestDemographic')
            ->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($collection);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/custodians/{custodianPid}/collections",
     *     summary="Get collections for a specific custodian",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="custodianPid",
     *         in="path",
     *         description="Public pid of the custodian",
     *         required=true,
     *         @OA\Schema(type="string", example="cust_abc123")
     *     ),
     *     @OA\Parameter(
     *         name="state",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="active")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of collections for the custodian",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Collection"))
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Custodian not found")
     * )
     */
    public function indexByCustodian(Request $request, string $custodianPid): JsonResponse
    {
        [$custodian, $error] = $this->getAuthorisedCustodian($custodianPid);
        if ($error) {
            return $error;
        }

        try {
            $perPage = $this->resolvePerPage();
            $collections = Collection::query()
                ->with([
                    'host',
                    'custodian',
                    'config',
                    'modelState.state',
                    'latestDemographic.task',
                    'latestConcept.task',
                    'latestDemographicTask',
                    'latestConceptTask',
                ])
                ->withCount(['concepts as n_concepts'])
                ->where('custodian_id', $custodian->id)
                ->when($request->filled('state'), function ($q) use ($request) {
                    if ($request->state !== 'all') {
                        $q->whereRelation('modelState.state', 'states.slug', strtolower($request->state));
                    }
                })
                ->searchViaRequest()
                ->filterViaRequest()
                ->applySorting()
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@indexByCustodian - failed: '.
                $e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/custodians/{custodianPid}/collections",
     *     summary="Create a collection for a given custodian",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="custodianPid",
     *         in="path",
     *         description="Public pid of the custodian",
     *         required=true,
     *         @OA\Schema(type="string", example="cust_abc123")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Collection created for custodian",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Custodian not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function storeByCustodian(Request $request, string $custodianPid): JsonResponse
    {
        [$custodian, $error] = $this->getAuthorisedCustodian($custodianPid);
        if ($error) {
            return $error;
        }

        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string', 'max:65535'],
                'url' => ['nullable', 'url', 'max:2048'],
                'type' => ['required', Rule::enum(QueryContextType::class)],
                'host_id' => [
                    'required',
                    'integer',
                    Rule::exists('collection_hosts', 'id')
                        ->where(fn ($q) => $q->where('custodian_id', $custodian->id)),
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        try {
            $collection = Collection::create([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'url' => $validated['url'] ?? null,
                'pid' => Str::uuid(),
                'type' => $validated['type'],
                'custodian_id' => $custodian->id,
            ]);

            $collection->host()->sync([$validated['host_id']]);

            return $this->CreatedResponse($collection);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/collections/{id}/transition_to",
     *     summary="Transition a Collection to a new state",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *              @OA\Property(property="state", type="string", example="active")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Collection state transitioned", @OA\JsonContent(ref="#/components/schemas/Collection")),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Collection not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function transitionTo(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['id']);
            if ($collection->isInState($validated['state'])) {
                return $this->ErrorResponse('collection is already in state: \"'.$validated['state'].'\"');
            }
            $this->stateService->transition($collection, $validated['state'], $request->user());

            return $this->OKResponse($collection);

        } catch (\Throwable $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collections/status/{status}",
     *     summary="Get collections by status",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="status",
     *         in="path",
     *         required=true,
     *         description="Status name (case-insensitive)",
     *         @OA\Schema(type="string", example="active")
     *     ),
     *     @OA\Response(response=200, description="Paginated collections matching the status", @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Collection")))
     * )
     */
    public function getByStatus(Request $request, string $status): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();

            $input = CollectionStatus::tryFromName($status) ?? CollectionStatus::ACTIVE;
            $collections = Collection::where('status', $input->value)
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    protected function getAuthorisedCustodian(string $pid): array
    {
        $custodian = Custodian::where('pid', $pid)->first();
        if (! $custodian) {
            return [null, $this->NotFoundResponse()];
        }
        if (Gate::denies('access', $custodian)) {
            return [null, $this->ForbiddenResponse()];
        }

        return [$custodian, null];
    }

    /**
     * @OA\Post(
     *     path="/api/v1/collections/{collectionId}/workgroup",
     *     summary="Add a collection to a workgroup",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="collectionId",
     *         in="path",
     *         description="Collection ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"workgroup_id"},
     *             @OA\Property(property="workgroup_id", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Collection added to workgroup",
     *         @OA\JsonContent(ref="#/components/schemas/WorkgroupHasCollection")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Collection or Workgroup not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function addToWorkgroup(Request $request, int $collectionId): JsonResponse
    {
        $input = $request->validate(app(Collection::class)->getValidationRules('addToWorkgroup'));

        try {
            $collection = Collection::findOrFail($collectionId);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($input['workgroup_id']);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $workgroupHasCollection = WorkgroupHasCollection::firstOrCreate([
            'collection_id' => $collection->id,
            'workgroup_id' => $input['workgroup_id'],
        ]);

        return $this->OKResponse([$workgroupHasCollection]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/collections/{collectionId}/workgroup/{workgroupId}",
     *     summary="Remove a collection from a workgroup",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="collectionId",
     *         in="path",
     *         description="Collection ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Parameter(
     *         name="workgroupId",
     *         in="path",
     *         description="Workgroup ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Response(response=200, description="Removed from workgroup"),
     *     @OA\Response(response=404, description="Collection or Workgroup not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function removeFromWorkgroup(Request $request, int $collectionId, int $workgroupId): JsonResponse
    {
        $input = $request->validate(app(Collection::class)->getValidationRules('removeFromWorkgroup'));

        try {
            $collection = Collection::findOrFail($collectionId);
        } catch (ModelNotFoundException $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($workgroupId);
        } catch (ModelNotFoundException $e) {
            return $this->NotFoundResponse();
        }

        $workgroupHasCollection = WorkgroupHasCollection::where([
            'collection_id' => $collection->id,
            'workgroup_id' => $workgroupId,
        ])->delete();

        if ($workgroupHasCollection) {
            return $this->OKResponse([]);
        }

        return $this->BadRequestResponse();
    }


    /**
     * @OA\Get(
     *     path="/api/v1/collection/{pid}/tasks",
     *     summary="Get tasks for a collection",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         required=true,
     *         description="Collection PID",
     *         @OA\Schema(type="string", example="2")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of tasks for the collection",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Task")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Collection not found")
     * )
     */
    public function getCollectionTasks($pid): JsonResponse
    {
        $collection = Collection::where('pid', $pid)->first();

        if (!$collection) {
            return $this->NotFoundResponse();
        }

        $tasks = $collection->tasks()
            ->with('submittedQuery')
            ->filterViaRequest()
            ->applySorting('created_at', 'desc');

        return $this->OKResponse($tasks->get());
    }

}
