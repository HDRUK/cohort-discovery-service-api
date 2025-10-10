<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Custodian;
use App\Services\QueryContext\QueryContextType;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getCollection($pid): JsonResponse
    {
        $collection = Collection::where('pid', $pid)
            ->with('size')
            ->first();

        if (!$collection) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($collection);
    }

    public function getCollections(): JsonResponse
    {
        $collections = Collection::with('demographics')->get();

        return $this->OKResponse($collections);
    }

    public function indexByCustodian(Request $request, string $custodianPid): JsonResponse
    {
        [$custodian, $error] = $this->getAuthorisedCustodian($custodianPid);
        if ($error) {
            return $error;
        }

        $perPage = $this->resolvePerPage();
        $collections = Collection::query()
            ->with(['host'])
            ->where('custodian_id', $custodian->id)
            ->paginate($perPage);

        return $this->OKResponse($collections);
    }

    public function storeByCustodian(Request $request, string $custodianPid): JsonResponse
    {
        [$custodian, $error] = $this->getAuthorisedCustodian($custodianPid);
        if ($error) {
            return $error;
        }

        try {
            $validated = $request->validate([
                'name'    => ['required', 'string', 'max:255'],
                // to-do / to-be-implemented: decision pending
                //'description'    => ['required', 'string', 'max:255'],
                'url'     => ['nullable', 'url', 'max:2048'],
                'type'    => ['required', Rule::enum(QueryContextType::class)],
                'host_id' => [
                    'required',
                    'integer',
                    Rule::exists('collection_hosts', 'id')
                        ->where(fn ($q) => $q->where('custodian_id', $custodian->id))
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        try {
            $collection = Collection::create([
                'name'         => $validated['name'],
                'url'          => $validated['url'] ?? null,
                'pid'          => Str::uuid(),
                'type'         => $validated['type'],
                'custodian_id' => $custodian->id,
            ]);

            $collection->host()->sync([$validated['host_id']]);


            return $this->CreatedResponse($collection);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }

    protected function getAuthorisedCustodian(string $pid): array
    {
        $custodian = Custodian::where('pid', $pid)->first();
        if (!$custodian) {
            return [null, $this->NotFoundResponse()];
        }
        if (Gate::denies('access', $custodian)) {
            return [null, $this->ForbiddenResponse()];
        }

        return [$custodian, null];
    }
}
