<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Traits\Responses;
use App\Models\Workgroup;

/**
 * @OA\Tag(
 *     name="Workgroups",
 *     description="API Endpoints for managing workgroups"
 * )
 */
class WorkgroupController extends Controller
{
    use Responses;

    /**
     * Intentionally left out of Swagger documentation as this is not a public endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $workgroup = Workgroup::all();
        return $this->OKResponse($workgroup);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Get a workgroup by ID",
     *     tags={"Workgroups"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Workgroup found",
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Workgroup not found"
     *     )
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
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
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="Research Team"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Workgroup created",
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ]);

        $workgroup = Workgroup::create($data);

        return $this->CreatedResponse($workgroup);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Update a workgroup",
     *     tags={"Workgroups"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", example="Updated Team"),
     *             @OA\Property(property="active", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Workgroup updated",
     *         @OA\JsonContent(ref="#/components/schemas/Workgroup")
     *     ),
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
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'active' => 'sometimes|boolean',
        ]);

        $workgroup->update($data);
        return $this->OKResponse($workgroup);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workgroups/{id}",
     *     summary="Delete a workgroup",
     *     tags={"Workgroups"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
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
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $workgroup = Workgroup::findOrFail($id);
            $workgroup->delete();
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse([]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workgroups/users",
     *     summary="Get users for workgroups",
     *     tags={"Workgroups"},
     *     @OA\Response(
     *         response=200,
     *         description="List of workgroups with users",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Workgroup"))
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No workgroups found"
     *     )
     * )
     */
    public function users(Request $request): JsonResponse
    {
        $workgroups = Workgroup::searchViaRequest()
            ->with('users')->get();

        if ($workgroups->isEmpty()) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($workgroups);
    }
}
