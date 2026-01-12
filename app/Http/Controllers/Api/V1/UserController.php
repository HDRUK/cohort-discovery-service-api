<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(
 *     name="Users",
 *     description="Endpoints for managing users and their workgroup memberships"
 * )
 */
class UserController extends Controller
{
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/users",
     *     summary="List users",
     *     tags={"Users"},
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
     *         description="List of users",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/User"))
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['workgroups','custodians'])
            ->searchViaRequest()
            ->withStatus()
            ->applySorting()
            ->get();

        return $this->OKResponse($users);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/users/{id}",
     *     summary="Get a single user by ID",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User object",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        // Stub
        $user = User::where('id', $id)->first();
        if ($user) {
            return $this->OKResponse($user);
        }

        return $this->NotFoundResponse();
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users",
     *     summary="Create a new user",
     *     tags={"Users"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User created",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/users/{id}",
     *     summary="Update an existing user",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated user",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{id}",
     *     summary="Delete a user",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=2)
     *     ),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // Stub
        return $this->OKResponse([]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/user",
     *     summary="Get the authenticated user's details",
     *     tags={"Users"},
     *     @OA\Response(
     *         response=200,
     *         description="Authenticated user object",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function getMe(Request $request)
    {
        $user = User::with(['workgroups', 'roles', 'custodians'])
            ->findOrFail(Auth::id());

        return $this->OKResponse($user);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/users/{id}/workgroups",
     *     summary="Add a user to a workgroup",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="User ID",
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
     *         description="User added to workgroup",
     *         @OA\JsonContent(ref="#/components/schemas/UserHasWorkgroup")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="User or Workgroup not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function addToWorkgroup(Request $request, int $id): JsonResponse
    {
        $input = $request->validate([
            'workgroup_id' => 'required|exists:workgroups,id',
        ]);

        try {
            $user = User::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($input['workgroup_id']);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $userHasWorkgroup = UserHasWorkgroup::firstOrCreate([
            'user_id' => $user->id,
            'workgroup_id' => $input['workgroup_id'],
        ]);

        return $this->OKResponse([$userHasWorkgroup]);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/users/{id}/workgroup/{workgroupId}",
     *     summary="Remove a user from a workgroup",
     *     tags={"Users"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="User ID",
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
     *     @OA\Response(response=404, description="User or Workgroup not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function removeFromWorkgroup(Request $request, int $id, int $workgroupId): JsonResponse
    {
        $input = $request->validate([]);

        try {
            $user = User::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        try {
            $workgroup = Workgroup::findOrFail($workgroupId);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $userHasWorkgroup = UserHasWorkgroup::where([
            'user_id' => $id,
            'workgroup_id' => $workgroupId,
        ])->delete();

        if ($userHasWorkgroup) {
            return $this->OKResponse([]);
        }

        return $this->BadRequestResponse();
    }
}
