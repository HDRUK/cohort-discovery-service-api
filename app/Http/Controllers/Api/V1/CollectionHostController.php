<?php

namespace App\Http\Controllers\Api\V1;

use Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Traits\Responses;
use App\Models\CollectionHost;
use App\Models\Custodian;

/**
 * @OA\Tag(
 *     name="CollectionHosts",
 *     description="API Endpoints for managing collection hosts"
 * )
 */
class CollectionHostController extends Controller
{
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/collection-hosts",
     *     summary="Get all collection hosts",
     *     tags={"CollectionHosts"},
     *     @OA\Response(
     *         response=200,
     *         description="List of collection hosts",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/CollectionHost"))
     *     )
     * )
     */
    public function index(ModelBackedRequest $request): JsonResponse
    {
        $hosts = CollectionHost::with('collections')
            ->searchViaRequest()
            ->filterViaRequest()
            ->applySorting()
            ->get();

        return $this->OKResponse($hosts);
    }

    public function indexByCustodian(Request $request, string $custodianPid): JsonResponse
    {
        $custodian = Custodian::where('pid', $custodianPid)->first();
        $custodianId = $custodian->id;

        return $this->OKResponse(CollectionHost::where('custodian_id', $custodianId)->with('collections')->get());
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collection-hosts/{id}",
     *     summary="Get a collection host by ID",
     *     tags={"CollectionHosts"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection host found",
     *         @OA\JsonContent(ref="#/components/schemas/CollectionHost")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Collection host not found"
     *     )
     * )
     */
    public function show(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collectionHost = CollectionHost::with('collections')->findOrFail($validated['id']);
            return $this->OKResponse($collectionHost);
        } catch (\Exception $e) {
            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/collection-hosts",
     *     summary="Create a new collection host",
     *     tags={"CollectionHosts"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/CollectionHost")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Collection host created",
     *         @OA\JsonContent(ref="#/components/schemas/CollectionHost")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $rawClientId = Str::uuid()->toString();
            $rawClientSecret = Str::random(64);

            $collectionHost = CollectionHost::create([
                'name' => $validated['name'],
                'query_context_type' => $validated['query_context_type'],
                'client_id' => hash('sha256', config('system.salt_1') . $rawClientId . config('system.salt_2')),
                'client_secret' => hash('sha256', config('system.salt_1') . $rawClientSecret . config('system.salt_2')),
                'custodian_id' => $validated['custodian_id'],
            ]);

            return $this->CreatedResponse($collectionHost);
        } catch (\Throwable $e) {
            \Log::error('CollectionHostController@store - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getTraceAsString() . ')');
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/collection-hosts/{id}",
     *     summary="Update a collection host",
     *     tags={"CollectionHosts"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/CollectionHost")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection host updated",
     *         @OA\JsonContent(ref="#/components/schemas/CollectionHost")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Collection host not found"
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
            $collectionHost = CollectionHost::findOrFail($validated['id']);
            $collectionHost->update($validated);

            return $this->OKResponse($collectionHost);
        } catch (\Throwable $e) {
            \Log::error('CollectionHostController@update - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getTraceAsString() . ')');

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/collection-hosts/{id}",
     *     summary="Delete a collection host",
     *     tags={"CollectionHosts"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Collection host deleted"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Collection host not found"
     *     )
     * )
     */
    public function destroy(ModelBackedRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collectionHost = CollectionHost::findOrFail($validated['id']);
            $collectionHost->delete();

            return $this->OKResponse([]);
        } catch (\Exception $e) {
            \Log::error('CollectionHostController@destroy - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getTraceAsString() . ')');

            return $this->NotFoundResponse();
        }
    }
}
