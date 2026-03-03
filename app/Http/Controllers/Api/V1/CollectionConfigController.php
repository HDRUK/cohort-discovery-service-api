<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
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
        $this->authorize('viewAny');

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
            $this->authorize('view', $config);

            return $this->OKResponse($config);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Exception $e) {
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
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('store'));

        $collection = Collection::findOrFail($validated['collection_id']);
        $this->authorize('create', [CollectionConfig::class, $collection]);

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
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('update'));

        $config = CollectionConfig::findOrFail($validated['id']);
        $this->authorize('update', $config);

        try {
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
        $request->merge(['id' => $id]);
        $validated = $request->validate(app(CollectionConfig::class)->getValidationRules('delete'));

        $config = CollectionConfig::findOrFail($validated['id']);
        $this->authorize('delete', $config);

        try {
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
