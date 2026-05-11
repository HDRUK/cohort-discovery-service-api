<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workgroup;
use App\Services\Activity\ActivityLogger;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * @OA\Tag(
 *     name="Workgroups",
 *     description="API Endpoints for managing workgroups"
 * )
 */
class WorkgroupController extends Controller
{
    use Responses;
    use AuthorizesRequests;

    /**
     * Intentionally left out of Swagger documentation as this is not a public endpoint.
     */
    public function index(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        $this->authorize('viewAny', Workgroup::class);

        $workgroup = Workgroup::all();

        $activityLogger->viewed('workgroups', null, [
            'filters' => $request->query(),
            'result' => [
                'total' => $workgroup->count(),
                'workgroup_ids' => $workgroup->pluck('id')->values()->all(),
            ],
        ]);

        return $this->OKResponse($workgroup);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Get a workgroup by ID",
     *     tags={"Workgroups"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workgroup found",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Workgroup not found"
     *     )
     * )
     */
    public function show(Request $request, int $id, ActivityLogger $activityLogger): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(Workgroup::class)->getValidationRules('show'));

        try {
            $workgroup = Workgroup::with('collections')->findOrFail($validated['id']);
            $this->authorize('view', $workgroup);

            $activityLogger->viewed('workgroups', $workgroup);

        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($workgroup);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/workgroups",
     *     summary="Create a new workgroup",
     *     tags={"Workgroups"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", example="Research Team"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Workgroup created",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        $validated = $request->validate(app(Workgroup::class)->getValidationRules('store'));
        $this->authorize('create', Workgroup::class);

        try {
            $workgroup = Workgroup::create($validated);

            $activityLogger->created('workgroups', $workgroup);

            return $this->CreatedResponse($workgroup);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('WorkgroupController@store - failed: '.json_encode($validated));

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Update a workgroup",
     *     tags={"Workgroups"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             @OA\Property(property="name", type="string", example="Updated Team"),
     *             @OA\Property(property="active", type="boolean", example=false)
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workgroup updated",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Workgroup not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(Request $request, int $id, ActivityLogger $activityLogger): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(Workgroup::class)->getValidationRules('update'));

        try {
            $workgroup = Workgroup::findOrFail($validated['id']);
            $this->authorize('update', $workgroup);

            $before = $workgroup->only(array_keys($validated));

            $workgroup->update($validated);
            $workgroup->refresh();

            $activityLogger->updated(
                'workgroups',
                $workgroup,
                $before,
                $workgroup->only(array_keys($validated))
            );

            return $this->OKResponse($workgroup);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('WorkgroupController@update - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Delete a workgroup",
     *     tags={"Workgroups"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Workgroup deleted"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Workgroup not found"
     *     )
     * )
     */
    public function destroy(Request $request, int $id, ActivityLogger $activityLogger): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(Workgroup::class)->getValidationRules('delete'));

        try {
            $workgroup = Workgroup::findOrFail($validated['id']);
            $this->authorize('delete', $workgroup);

            $workgroup->delete();

            $activityLogger->deleted('workgroups', $workgroup);

            return $this->OKResponse([]);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('WorkgroupController@destroy/'.$validated['id'].' - failed: '.
                $e->getMessage());

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workgroups/search/users",
     *     summary="Get users by workgroups",
     *     tags={"Workgroups"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of workgroups with users",
     *
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Workgroup"))
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="No workgroups found"
     *     )
     * )
     */
    public function usersByWorkgroup(Request $request, ActivityLogger $activityLogger): JsonResponse
    {
        try {
            $this->authorize('searchUsers', Workgroup::class);

            $workgroups = Workgroup::searchViaRequest()
                ->with('users')
                ->get();

            if ($workgroups->isEmpty()) {
                return $this->NotFoundResponse();
            }

            $activityLogger->viewed('workgroups', null, [
                'filters' => $request->query(),
                'result' => [
                    'total' => $workgroups->count(),
                    'workgroup_ids' => $workgroups->pluck('id')->values()->all(),
                ],
            ], 'workgroup_users_viewed');

            return $this->OKResponse($workgroups);
        } catch (AuthorizationException $e) {
            throw $e;
        }
    }
}
