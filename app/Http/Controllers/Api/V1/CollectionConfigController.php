<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionConfig;
use App\Traits\Responses;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionConfigController extends Controller
{
    use Responses;
    use AuthorizesRequests;

    /**
     * @OA\Get(
     *     path="/api/v1/collection-configs",
     *     summary="Get all collection configs",
     *     tags={"CollectionConfig"},
     *
     *     @OA\Response(
     *         response=200,
     *         description="List of collection configs",
     *
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/CollectionConfig"))
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $configs = CollectionConfig::all();

            return $this->OKResponse($configs);
        } catch (\Throwable $e) {
            \Log::error('CollectionConfigController@index - failed');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/collection-configs/{id}",
     *     summary="Get a collection config by ID",
     *     tags={"CollectionConfig"},
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
     *         description="CollectionConfig found",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CollectionConfig")
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="CollectionConfig not found"
     *     )
     * )
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('show'));

        try {
            $config = CollectionConfig::findOrFail($validated['id']);

            return $this->OKResponse($config);

        } catch (\Throwable $e) {
            \Log::error('CollectionConfigController@show/'.$id.' - failed: '.$e->getMessage());

            return $this->NotFoundResponse();
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/collection-configs",
     *     summary="Create a new collection config",
     *     tags={"CollectionConfig"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(ref="#/components/schemas/CollectionConfig")
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="CollectionConfig created",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CollectionConfig")
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CollectionConfig::class);

        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('store'));

        try {
            $config = CollectionConfig::create($validated);

            return $this->CreatedResponse($config);

        } catch (\Throwable $e) {
            \Log::error('CollectionConfigController@store - failed: '.json_encode($validated));

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/collection-configs/{id}",
     *     summary="Update an existing collection config",
     *     tags={"CollectionConfig"},
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
     *         @OA\JsonContent(ref="#/components/schemas/CollectionConfig")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="CollectionConfig updated",
     *
     *         @OA\JsonContent(ref="#/components/schemas/CollectionConfig")
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="CollectionConfig not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorize('update', CollectionConfig::class);

        $request->merge(['id' => $id]);
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('update'));

        try {
            $config = CollectionConfig::findOrFail($validated['id']);
            if ($config->update($validated)) {
                return $this->OKResponse($config);
            }

            return $this->BadRequestResponseExtended('unable to update CollectionConfig');

        } catch (\Throwable $e) {
            \Log::error('CollectionConfigController@update - failed: '.json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/collection-configs/{id}",
     *     summary="Delete a collection config",
     *     tags={"CollectionConfig"},
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
     *         description="CollectionConfig deleted"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="CollectionConfig not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="CollectionConfig cannot be deleted due to unknown error"
     *     )
     * )
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorize('delete', CollectionConfig::class);

        $request->merge(['id' => $id]);
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('delete'));

        try {
            $config = CollectionConfig::findOrFail($validated['id']);

            if ($config->delete()) {
                return $this->OKResponse([]);
            }

            return $this->ErrorResponse();
        } catch (\Throwable $e) {
            \Log::error('CollectionConfigController@destroy/'.$id.' - failed: '.$e->getMessage());

            return $this->ErrorResponse($e->getMessage());
        }
    }
}
