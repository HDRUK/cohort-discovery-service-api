<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Custodian;
use App\Services\QueryContext\QueryContextType;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Hdruk\LaravelSearchAndFilter\Traits\Search;

class CollectionController extends Controller
{
    use Responses;
    use HelperFunctions;
    use Search;

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

    public function getCollections(Request $request): JsonResponse
    {
        $input = $request->query('custodian_name', null);

        $collections = Collection::with('demographics')
            ->whereHas('custodian', function ($query) use ($input) {
                if (empty($input)) {
                    return;
                }

                $query->where('name', 'LIKE', '%' . $input . '%');
            })
            ->searchViaRequest()
            ->applySorting()
            ->get();

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
            ->searchViaRequest()
            ->applySorting()
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

    public function getByStatus(Request $request, string $status): JsonResponse
    {
        try {
            $perPage = $this->resolvePerPage();
            $input = $status;
            if (!in_array($input, [
                Collection::STATUS_ACTIVE,
                Collection::STATUS_INACTIVE,
            ])) {
                $input = Collection::STATUS_ACTIVE;
            }

            $collections = Collection::where('status', ($status === Collection::STATUS_ACTIVE ? 1 : 0))
                ->paginate($perPage);
            return $this->OKResponse($collections);

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
