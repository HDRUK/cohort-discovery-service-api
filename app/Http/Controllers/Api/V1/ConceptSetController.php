<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConceptSet;
use App\Models\ConceptSetHasConcept;
use App\Models\Distribution;
use App\Traits\Responses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConceptSetController extends Controller
{
    use Responses;

    public function index(Request $request)
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

    public function show(Request $request, ConceptSet $conceptSet)
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

    public function store(Request $request)
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

    public function update(Request $request, ConceptSet $conceptSet)
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

    public function attachConcept(Request $request, ConceptSet $conceptSet, int $conceptId)
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

    public function detachConcept(Request $request, ConceptSet $conceptSet, int $conceptId)
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

    public function clear(Request $request, ConceptSet $conceptSet)
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
