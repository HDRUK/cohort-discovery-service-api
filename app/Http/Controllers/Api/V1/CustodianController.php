<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Custodian;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
use App\Traits\Responses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Custodians",
 *     description="API Endpoints for managing custodians"
 * )
 */
class CustodianController extends Controller
{
    use Responses;
    use AuthorizesRequests;

    /**
     * @OA\Get(
     *     path="/api/v1/custodians",
     *     summary="Get all custodians",
     *     tags={"Custodians"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of custodians",
     *
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Custodian"))
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        //$this->authorize('viewAny', Custodian::class);

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
     *         description="Custodian found",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Custodian not found"
     *     )
     * )
     */
    public function show(ModelBackedRequest $request, mixed $key = null): JsonResponse
    {
        $validated = $request->validated();

        try {
            $custodian = Custodian::with([
                'hosts',
                'network',
            ])->when(
                ctype_digit($key),
                fn ($q) => $q->where('id', $key),
                fn ($q) => $q->where('pid', $key)
            )
            ->firstOrFail();

            $this->authorize('view', $custodian);

            return $this->OKResponse($custodian);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/custodians",
     *     summary="Create a new custodian",
     *     tags={"Custodians"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Custodian created",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->authorize('create', Custodian::class);

        try {
            $custodian = Custodian::create($validated);

            return $this->CreatedResponse($custodian);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('CustodianController@store - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/custodians/{id}",
     *     summary="Update a custodian",
     *     tags={"Custodians"},
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
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Custodian updated",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Custodian")
     *     ),
     *
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
    public function update(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $custodian = Custodian::findOrFail($validated['id']);
            $this->authorize('update', $custodian);

            $custodian->update($validated);

            return $this->OKResponse($custodian);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('CustodianController@update - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/custodians/{id}",
     *     summary="Delete a custodian",
     *     tags={"Custodians"},
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
     *         description="Custodian deleted"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Custodian not found"
     *     )
     * )
     */
    public function destroy(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $custodian = Custodian::findOrFail($validated['id']);
            $this->authorize('delete', $custodian);

            $custodian->delete();

            return $this->OKResponse([]);
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('CustodianController@update - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

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
            \Log::error('CustodianController@linkToNetwork - failed: (exception: '.$e->getMessage().')');

            return $this->BadRequestResponse();
        }
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

            return $this->BadRequestResponse();
        } catch (\Throwable $e) {
            \Log::error('CustodianController@unlinkFromNetwork - failed: (exception: '.$e->getMessage().')');

            return $this->BadRequestResponse();
        }
    }
}
