<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConceptSet;
use App\Models\ConceptSetHasConcept;
use App\Models\Distribution;
use App\Traits\Responses;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @OA\Tag(
 *     name="ConceptSets",
 *     description="Endpoints for managing user-defined concept sets"
 * )
 */
class ConceptSetController extends Controller
{
    use Responses;

    /**
     * @OA\Get(
     *     path="/api/v1/concept-sets",
     *     summary="List concept sets for the authenticated user",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="domain",
     *         in="query",
     *         description="Filter by OMOP domain",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="List of concept sets",
     *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/ConceptSet"))
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $query = ConceptSet::with(['concepts' => function ($q) {
            $q->select(
                'distributions.concept_id',
                'distributions.description',
                'distributions.category'
            )->distinct();
        }])->where('user_id', Auth::id());

        if ($domain = $request->query('domain')) {
            $query->forDomain($domain);
        }

        return $this->OKResponse($query->get());
    }

    /**
     * @OA\Get(
     *     path="/api/v1/concept-sets/{conceptSet}",
     *     summary="Get a single concept set by ID",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="ConceptSet record",
     *         @OA\JsonContent(ref="#/components/schemas/ConceptSet")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function show(Request $request, ConceptSet $conceptSet): JsonResponse
    {
        if (Gate::denies('view', $conceptSet)) {
            return  $this->ForbiddenResponse();
        }
        $conceptSet->load([
            'concepts' => function ($q) {
                $q->select(
                    'distributions.concept_id',
                    'distributions.description',
                    'distributions.category'
                )
                    ->distinct();
            },
        ]);

        return $this->OKResponse($conceptSet);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/concept-sets",
     *     summary="Create a new concept set",
     *     tags={"ConceptSets"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/ConceptSet")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="ConceptSet created",
     *         @OA\JsonContent(ref="#/components/schemas/ConceptSet")
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'domain'      => 'required|string',
                'description' => 'nullable|string',
            ]);

            $validated['user_id'] = Auth::id();

            $conceptSet = ConceptSet::create($validated);

            return $this->CreatedResponse($conceptSet);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/concept-sets/{conceptSet}",
     *     summary="Update an existing concept set",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(ref="#/components/schemas/ConceptSet")
     *     ),
     *     @OA\Response(response=200, description="Updated concept set", @OA\JsonContent(ref="#/components/schemas/ConceptSet")),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, ConceptSet $conceptSet): JsonResponse
    {
        if (Gate::denies('view', $conceptSet)) {
            return  $this->ForbiddenResponse();
        }
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'domain'      => 'required|string',
                'description' => 'nullable|string',
            ]);

            $conceptSet->update($validated);
            $conceptSet->save();

            return $this->OKResponse($conceptSet->fresh());
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/concept-sets/{conceptSet}",
     *     summary="Delete a concept set",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Deleted"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function destroy(ConceptSet $conceptSet): JsonResponse
    {
        try {
            $conceptSet->delete();
            return $this->OKResponse([]);
        } catch (\Exception $e) {
            return $this->ErrorResponse('Unable to delete concept set.');
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/concept-sets/{conceptSet}/concepts/{conceptId}",
     *     summary="Attach a concept to a concept set",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="conceptId",
     *         in="path",
     *         description="OMOP concept id to attach",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Concept attached to set",
     *         @OA\JsonContent(ref="#/components/schemas/ConceptSetHasConcept")
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Concept not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function attachConcept(Request $request, ConceptSet $conceptSet, int $conceptId): JsonResponse
    {
        if (Gate::denies('view', $conceptSet)) {
            return  $this->ForbiddenResponse();
        }
        try {
            $query = Distribution::where('concept_id', $conceptId);

            $exists = $query->clone()->exists();
            if (!$exists) {
                return $this->NotFoundResponse();
            }

            $countMismatched = $query
                ->where('category', '!=', $conceptSet->domain)
                ->count();

            if ($countMismatched > 0) {
                return $this->ValidationErrorResponse(['concept_id' => 'Concept does not exist in the domain for this concept set']);
            }


            $conceptSetHasConcept = ConceptSetHasConcept::firstOrCreate(
                ['concept_set_id' => $conceptSet->id, 'concept_id' => $conceptId]
            );

            return $this->CreatedResponse($conceptSetHasConcept);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/concept-sets/{conceptSet}/concepts/{conceptId}",
     *     summary="Detach a concept from a concept set",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="conceptId",
     *         in="path",
     *         description="OMOP concept id to detach",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Detached"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function detachConcept(Request $request, ConceptSet $conceptSet, int $conceptId): JsonResponse
    {
        if (Gate::denies('view', $conceptSet)) {
            return  $this->ForbiddenResponse();
        }
        try {
            ConceptSetHasConcept::where('concept_set_id', $conceptSet->id)
                ->where('concept_id', $conceptId)
                ->delete();

            return $this->OKResponse([
                'details' => 'Concept detached successfully.',
                'concept_set_id' => $conceptSet->id,
                'concept_id' => $conceptId,
            ]);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/concept-sets/{conceptSet}/concepts",
     *     summary="Clear all concepts from a concept set",
     *     tags={"ConceptSets"},
     *     @OA\Parameter(
     *         name="conceptSet",
     *         in="path",
     *         description="ID of the ConceptSet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Concept set cleared"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function clear(Request $request, ConceptSet $conceptSet): JsonResponse
    {
        if (Gate::denies('view', $conceptSet)) {
            return  $this->ForbiddenResponse();
        }
        try {
            ConceptSetHasConcept::where('concept_set_id', $conceptSet->id)->delete();

            return $this->OKResponse([
                'details' => 'Concept set cleared successfully.',
            ]);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }
}
