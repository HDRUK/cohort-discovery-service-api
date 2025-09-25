<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Custodian;
use App\Traits\Responses;

/**
 * @OA\Tag(
 *     name="Custodians",
 *     description="API Endpoints for managing custodians"
 * )
 */
class CustodianController extends Controller
{
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/custodians",
     *     summary="Get all custodians",
     *     tags={"Custodians"},
     *     @OA\Response(
     *         response=200,
     *         description="List of custodians",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Custodian"))
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        return $this->OKResponse(
            Custodian::with('hosts')
                ->searchViaRequest()
                ->applySorting()
                ->get()
        );
    }

    /**
     * @OA\Get(
     *     path="/api/v1/custodians/{id}",
     *     summary="Get a custodian by ID",
     *     tags={"Custodians"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custodian found",
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custodian not found"
     *     )
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $custodian = Custodian::with('hosts')->findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($custodian);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/custodians",
     *     summary="Create a new custodian",
     *     tags={"Custodians"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Custodian created",
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
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
            'pid' => 'nullable|string',
            'name' => 'required|string|max:255',
            'url' => 'nullable|url',
        ]);

        $custodian = Custodian::create($data);

        return $this->CreatedResponse($custodian);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/custodians/{id}",
     *     summary="Update a custodian",
     *     tags={"Custodians"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custodian updated",
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custodian not found"
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
            $custodian = Custodian::findOrFail($id);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'url' => 'nullable|url',
        ]);

        $custodian->update($data);

        return $this->OKResponse($custodian);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/custodians/{id}",
     *     summary="Delete a custodian",
     *     tags={"Custodians"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Custodian deleted"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custodian not found"
     *     )
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $custodian = Custodian::findOrFail($id);
            $custodian->delete();
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse([]);
    }
}
