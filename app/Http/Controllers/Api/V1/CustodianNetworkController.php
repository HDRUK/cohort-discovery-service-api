<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\CustodianNetwork;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;
use Str;

/**
 * @OA\Tag(
 *     name="CustodianNetworks",
 *     description="API Endpoints for managing custodian networks"
 * )
 */
class CustodianNetworkController extends Controller
{
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/custodian-networks",
     *     summary="Get all custodian networks",
     *     tags={"CustodianNetworks"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of custodian networks",
     *
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/CustodianNetwork"))
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        $networks = CustodianNetwork::with('custodians')
            ->searchViaRequest()
            ->filterViaRequest()
            ->applySorting()
            ->get();

        return $this->OKResponse($networks);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/custodian-networks/{id}",
     *     summary="Get a custodian network by ID",
     *     tags={"CustodianNetworks"},
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
     *         description="Custodian network found",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CustodianNetwork")
     *     ),
     *
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $network = CustodianNetwork::with('custodians')->findOrFail($validated['id']);

            return $this->OKResponse($network);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/custodian-networks",
     *     summary="Create a new custodian network",
     *     tags={"CustodianNetworks"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(ref="#/components/schemas/CustodianNetwork")
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Custodian network created",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CustodianNetwork")
     *     ),
     *
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $network = CustodianNetwork::create([
                'pid'  => Str::uuid(),
                'name' => $validated['name'],
                'url'  => $validated['url'] ?? null,
            ]);

            return $this->CreatedResponse($network);
        } catch (\Throwable $e) {
            \Log::error('CustodianNetworkController@store - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/custodian-networks/{id}",
     *     summary="Update a custodian network",
     *     tags={"CustodianNetworks"},
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
     *         @OA\JsonContent(ref="#/components/schemas/CustodianNetwork")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Custodian network updated",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CustodianNetwork")
     *     ),
     *
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $network = CustodianNetwork::findOrFail($validated['id']);
            $network->update($validated);

            return $this->OKResponse($network);
        } catch (\Throwable $e) {
            \Log::error('CustodianNetworkController@update - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/custodian-networks/{id}",
     *     summary="Delete a custodian network",
     *     tags={"CustodianNetworks"},
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $network = CustodianNetwork::findOrFail($validated['id']);
            $network->delete();

            return $this->OKResponse([]);
        } catch (\Throwable $e) {
            \Log::error('CustodianNetworkController@destroy - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');

            return $this->NotFoundResponse();
        }
    }
}
