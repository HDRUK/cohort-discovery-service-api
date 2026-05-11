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
use Illuminate\Support\Facades\Auth;

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
        $this->authorize('viewAny', Custodian::class);

        $custodians = Custodian::with([
            'hosts',
            'network',
        ])
            ->searchViaRequest()
            ->applySorting()
            ->get();

        activity('custodians')
            ->event('viewed')
            ->causedBy(Auth::user())
            ->withProperties([
                'filters' => $request->query(),
                'result' => [
                    'total' => $custodians->count(),
                    'custodian_ids' => $custodians->pluck('id')->values()->all(),
                    'custodian_pids' => $custodians->pluck('pid')->values()->all(),
                ],
            ])
            ->log('custodians_viewed');

        return $this->OKResponse($custodians);
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

            activity('custodians')
                ->event('viewed')
                ->causedBy(Auth::user())
                ->performedOn($custodian)
                ->withProperties([
                    'filters' => $request->query(),
                ])
                ->log('custodian_viewed');

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

            activity('custodians')
                ->event('created')
                ->causedBy(Auth::user())
                ->performedOn($custodian)
                ->log('custodian_created');

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

            $before = $custodian->only(array_keys($validated));

            $custodian->update($validated);
            $custodian->refresh();
            $after = $custodian->only(array_keys($validated));

            activity('custodians')
                ->event('updated')
                ->causedBy(Auth::user())
                ->performedOn($custodian)
                ->withProperties([
                    'before' => $before,
                    'after' => $after,
                ])
                ->log('custodian_updated');

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

            activity('custodians')
                ->event('deleted')
                ->causedBy(Auth::user())
                ->performedOn($custodian)
                ->log('custodian_deleted');

            return $this->OKResponse([]);
        } catch (AuthorizationException $e) {
            return $this->ForbiddenResponse();
        } catch (\Throwable $e) {
            \Log::error('CustodianController@update - failed: '.
                json_encode($validated).' (exception: '.$e->getMessage().')');

            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function linkToNetwork(Request $request, int $custodianId, int $networkId): JsonResponse
    {
        try {
            $custodian = Custodian::findOrFail($custodianId);
            $network = CustodianNetwork::findOrFail($networkId);

            //note - for now, only allow a custodian to be in one custodian network
            //       by deleting it from other networks
            $removedLinks = CustodianNetworkHasCustodian::where('custodian_id', $custodian->id)
                ->where('network_id', '!=', $network->id)
                ->delete();

            $link = CustodianNetworkHasCustodian::firstOrCreate([
                'custodian_id' => $custodian->id,
                'network_id' => $network->id,
            ]);

            activity('custodians')
                ->event('attached')
                ->causedBy(Auth::user())
                ->performedOn($custodian)
                ->withProperties([
                    'network' => [
                        'id' => $network->id,
                        'pid' => $network->pid,
                    ],
                    'result' => [
                        'removed_other_network_links' => $removedLinks,
                    ],
                ])
                ->log('custodian_linked_to_network');

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
                activity('custodians')
                   ->event('detached')
                    ->causedBy(Auth::user())
                    ->performedOn($custodian)
                    ->withProperties([
                        'network' => [
                            'id' => $network->id,
                            'pid' => $network->pid,
                        ],
                    ])
                    ->log('custodian_unlinked_from_network');

                return $this->OKResponse([]);
            }

            return $this->BadRequestResponse();
        } catch (\Throwable $e) {
            \Log::error('CustodianController@unlinkFromNetwork - failed: (exception: '.$e->getMessage().')');

            return $this->BadRequestResponse();
        }
    }
}
