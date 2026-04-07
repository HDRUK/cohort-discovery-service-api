<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Collection;
use App\Models\Custodian;
use App\Models\Task;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupHasCollection;
use App\Services\Collections\CollectionStateService;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Auth\Access\AuthorizationException;
use App\Jobs\RefreshDistributionConceptsView;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Collections\ProcessLatestCollectionMetadataService;

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
    use AuthorizesRequests;

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
        try {
            $collections = Collection::with([
                'demographics',
                'custodian.network',
                'modelState.state',
                'latestMetadata',
            ])
                ->searchViaRequest()
                ->filterViaRequest()
                ->applySorting()
                ->get();

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@index - failed: '.
                json_encode($request->all()).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }


    /**
     * @OA\Get(
     *     path="/api/v1/user/collections",
     *     summary="Get all collections for a user",
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
     *         description="List of collections for a user",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Collection"))
     *     )
     * )
     */
    public function indexForUser(ModelBackedRequest $request): JsonResponse
    {

        $user = User::with('custodians.collections')->find(Auth::id());

        $userWorkgroupsSubquery = $user
            ->workgroups()
            ->select('workgroups.id');

        $userCustodianIdsSubquery = $user
            ->custodians()
            ->select('custodians.id');

        $isAdmin = $user->roles()->where('name', 'admin')->exists();

        $collections = $user->custodians()
               ->with('collections')
               ->get()
               ->flatMap(fn (Custodian $c) => $c->collections)
               ->unique('id')
               ->values();


        $collections = Collection::with([
            'demographics',
            'custodian.network',
            'modelState.state',
            'latestMetadata',
        ])
            ->when(
                !$isAdmin,
                fn ($query) => $query->where(
                    fn ($q) =>
                        $q->where(
                            fn ($qq) =>
                                $qq->whereHas(
                                    'workgroups',
                                    fn ($wq) => $wq->whereIn(
                                        'workgroups.id',
                                        $userWorkgroupsSubquery
                                    )
                                )
                                ->whereRelation(
                                    'modelState.state',
                                    'states.slug',
                                    Collection::STATUS_ACTIVE
                                )
                        )
                        ->orWhereIn('custodian_id', $userCustodianIdsSubquery)
                )
            )
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
        $this->authorize('viewAnyForAdmin', Collection::class);

        try {
            $perPage = $this->resolvePerPage();

            $collections = $this->collectionsIndexQuery($request)
                ->when($request->filled('workgroup_id'), function ($q) use ($request) {
                    $q->whereRelation('workgroups', 'workgroups.id', $request->workgroup_id);
                })
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@indexForAdmin - failed: ' . $e->getMessage());

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
                'latestMetadata',
            ])->findOrFail($validated['id']);

            $this->authorize('view', $collection);

            return $this->OKResponse($collection);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('CollectionController@show - failed: '.
                json_encode($request->all()).' (exception: '.$e->getMessage().')');

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
            $custodian = Custodian::findOrFail($validated['custodian_id']);
            $this->authorize('create', $custodian);

            $collection = Collection::create($validated);

            return $this->CreatedResponse($collection);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
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
            $collection = Collection::with(['host', 'config', 'custodian'])->findOrFail($validated['id']);
            $this->authorize('update', $collection);

            if ($collection->update($validated)) {
                $collection->host()->sync([$validated['host_id']]);

                if ($collection->wasChanged('is_synthetic')) {
                    RefreshDistributionConceptsView::dispatch();
                }

                return $this->OKResponse($collection);
            }
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error(
                'CollectionController@update - failed: ' .
                json_encode($validated) .
                ' (exception: ' . $e->getMessage() . ')'
            );

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
            $this->authorize('delete', $collection);

            if ($collection->delete()) {
                return $this->OKResponse([]);
            }
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
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
            ->with(['latestDemographic','latestMetadata'])
            ->first();

        if (! $collection) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($collection);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collections/{pid}/details",
     *     summary="Get additional details about a collection by PID",
     *     tags={"Collections"},
     *     @OA\Parameter(
     *         name="pid",
     *         in="path",
     *         required=true,
     *         description="Collection PID",
     *         @OA\Schema(type="string", example="abc-def")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection found",
     *         @OA\JsonContent(ref="#/components/schemas/Collection")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Collection not found"
     *     )
     * )
     */
    public function getCollectionDetails(Request $request, string $pid): JsonResponse
    {
        try {

            $collection = Collection::where('pid', $pid)
                ->with([
                    'demographics',
                    'custodian',
                    'modelState.state',
                    'workgroups',
                    'latestMetadata',
                    'resultFiles' => function ($query) {
                        $query->with('task')->orderByDesc('updated_at');
                    },
                ])
                ->first();
            if (!$collection) {
                return $this->NotFoundResponse();
            }

            $nconcepts = $collection->concepts()
               ->count();

            $concept_counts_by_category = $collection->conceptCountsByCategory()
                ->orderBy('nconcepts', 'desc')
                ->get()
                ->map(fn ($row) => [
                        'category' => $row->getAttribute('category'),
                        'nconcepts' => (int) $row->getAttribute('nconcepts'),
                    ])
                ->values()
                ->toArray();

            return $this->OKResponse([
                ...$collection->toArray(),
                'nconcepts' => $nconcepts,
                'concept_counts_by_category' => $concept_counts_by_category,
            ]);

        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('CollectionController@getCollectionDetails - failed: '.
                json_encode($request->all()).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }


    public function getCollectionConcepts(Request $request, string $pid): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();
            $collection = Collection::where('pid', $pid)
                ->first();
            if (!$collection) {
                return $this->NotFoundResponse();
            }

            $data = $collection->concepts()
                ->select([
                    'distributions.id',
                    'distributions.collection_id',
                    'distributions.concept_id',
                    'distributions.description',
                    'distributions.category',
                    'distributions.count',
                ])
                ->orderBy('concept_id')
                ->paginate($perPage);

            return $this->OKResponse($data);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('CollectionController@getCollectionConcepts - failed: '.
                json_encode($request->all()).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
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

            $collections = $this->collectionsIndexQuery($request)
                ->where('custodian_id', $custodian->id)
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@indexByCustodian - failed: ' . $e->getMessage());

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
                'is_synthetic' => ['sometimes', 'boolean'],
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
                'is_synthetic' => $validated['is_synthetic'] ?? false,
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
            $this->authorize('update', $collection);

            if ($collection->isInState($validated['state'])) {
                return $this->ErrorResponse('collection is already in state: \"'.$validated['state'].'\"');
            }
            $this->stateService->transition($collection, $validated['state'], $request->user());

            return $this->OKResponse($collection);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
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
    *         description="Collection state slug (case-insensitive), for example active or suspended",
    *         @OA\Schema(type="string", example="active")
    *     ),
    *     @OA\Parameter(
    *         name="per_page",
    *         in="query",
    *         required=false,
    *         description="Number of results per page",
    *         @OA\Schema(type="integer", example=15)
    *     ),
    *     @OA\Response(
    *         response=200,
    *         description="Paginated collections matching the status"
    *     ),
    *     @OA\Response(
    *         response=422,
    *         description="Invalid status supplied"
    *     )
    * )
    */
    public function getByStatus(Request $request, string $status): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();
            $status = strtolower(trim($status));


            $collections = Collection::query()
                ->whereRelation('modelState.state', 'slug', $status)
                ->paginate($perPage);

            return $this->OKResponse($collections);
        } catch (\Throwable $e) {
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

        $tasks = Task::query()
            ->where('collection_id', $collection->id)
            ->with('submittedQuery')
            ->filterViaRequest()
            ->applySorting('created_at', 'desc');

        return $this->OKResponse($tasks->get());
    }

    public function processLatestMetadataFiles(
        Request $request,
        ProcessLatestCollectionMetadataService $service
    ): JsonResponse {
        $this->authorize('viewAnyForAdmin', Collection::class);

        try {
            $validated = $request->validate([
                'collection_ids' => ['nullable', 'array'],
                'collection_ids.*' => ['integer', Rule::exists('collections', 'id')],
            ]);

            $result = $service->handle(
                collectionIds: $validated['collection_ids'] ?? [],
            );

            return $this->OKResponse($result);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        } catch (\Throwable $e) {
            \Log::error(
                'CollectionController@processLatestMetadataFiles - failed: ' .
                json_encode($request->all()) .
                ' (exception: ' . $e->getMessage() . ')'
            );

            return $this->ErrorResponse($e->getMessage());
        }
    }


    protected function collectionsIndexQuery(Request $request): Builder
    {
        return Collection::query()
            ->with([
                'host',
                'custodian.network',
                'config',
                'modelState.state',
                'latestSuccessfulDemographicResultFile',
                'latestSuccessfulConceptResultFile',
                'workgroups',
                'latestMetadata',
            ])
            ->when($request->filled('state'), function ($q) use ($request) {
                if ($request->state !== 'all') {
                    $q->whereRelation('modelState.state', 'states.slug', strtolower($request->state));
                }
            })
            ->searchViaRequest()
            ->filterViaRequest()
            ->applySorting();
    }



}
