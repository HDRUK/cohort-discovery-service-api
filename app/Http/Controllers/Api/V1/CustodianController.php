<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Custodian;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
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
            Custodian::with([
                'hosts',
                'network',
            ])
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
            $custodian = Custodian::with([
                'hosts',
                'network'
            ])->findOrFail($id);
            
            return $this->OKResponse($custodian);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }
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
            $data = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'url' => 'nullable|url',
            ]);

            $custodian->update($data);

            return $this->OKResponse($custodian);            
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }
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

            return $this->OKResponse([]);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }
    }

    public function linkToNetwork(Request $request, int $custodianId, int $networkId): JsonResponse
    {
        try {
            $custodian = Custodian::findOrFail($custodianId);
            $network = CustodianNetwork::findOrFail($networkId);

            $link = CustodianNetworkHasCustodian::firstOrCreate([
                'custodian_id' => $custodian->id,
                'network_id' => $network->id,
            ]);

            return $this->OKResponse($link);
        } catch (\Throwable $e) {
            \Log::error('CustodianController@linkToNetwork - failed: (exception: ' . $e->getMessage() . ')');
        }

        return $this->BadRequestResponse();
    }

    public function unlinkFromNetwork(Request $request, int $custodianId, int $networkId): JsonResponse
    {
        try {
            $custodian = Custodian::findOrFail($custodianId);
            $network = CustodianNetwork::findOrFail($networkId);

            $link = CustodianNetworkHasCustodian::firstOrCreate([
                'custodian_id' => $custodian->id,
                'network_id' => $network->id,
            ]);

            if ($link->delete()) {
                return $this->OKResponse([]);
            }
        } catch (\Throwable $e) {
            \Log::error('CustodianController@unlinkFromNetwork - failed: (exception: ' . $e->getMessage() . ')');
        }

        return $this->BadRequestResponse();
    }
}
