<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\Collection;
use App\Models\Custodian;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use App\Http\Requests\ModelBackedRequest;
use App\Services\QueryContext\QueryContextType;
use App\Http\Controllers\Controller;
use App\Enums\CollectionStatus;

class CollectionController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function index(ModelBackedRequest $request): JsonResponse
    {
        $collections = Collection::with([
            'demographics',
            'custodian'
        ])
            ->searchViaRequest()
            ->filterViaRequest()
            ->applySorting()
            ->get();
        return $this->OKResponse($collections);
    }

    public function show(ModelBackedRequest $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['id']);
            return $this->OKResponse($collection);
        } catch (\Throwable $e) {
            return $this->NotFoundResponse();
        }
    }

    public function store(ModelBackedRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $collection = Collection::create($validated);
            return $this->CreatedResponse($collection);
        } catch (\Throwable $e) {
            \Log::error('CollectionController@store - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');
            return $this->ErrorResponse($e->getMessage());
        }
    }

    public function update(ModelBackedRequest $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['id']);
            if ($collection->update($validated)) {
                return $this->OKResponse($collection);
            }
        } catch (\Throwable $e) {
            \Log::error('CollectionController@update - failed: ' .
                json_encode($validated) . ' (exception: ' . $e->getMessage() . ')');
            return $this->NotFoundResponse();
        }

        return $this->ErrorResponse();
    }

    public function destroy(ModelBackedRequest $request, int $id): JsonResponse
    {
        $request->merge(['id' => $id]);
        $validated = $request->validated();

        try {
            $collection = Collection::findOrFail($validated['id']);
            if ($collection->delete()) {
                return $this->OKResponse([]);
            }
        } catch (\Throwable $e) {
            \Log::error('CollectionController@destroy - failed: ' .
                $e->getMessage());
            return $this->NotFoundResponse();
        }

        return $this->ErrorResponse();
    }

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
            ->filterViaRequest()
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
                'description'    => ['required', 'string', 'max:65535'],
                'url'     => ['nullable', 'url', 'max:2048'],
                'type'    => ['required', Rule::enum(QueryContextType::class)],
                'host_id' => [
                    'required',
                    'integer',
                    Rule::exists('collection_hosts', 'id')
                        ->where(fn($q) => $q->where('custodian_id', $custodian->id))
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->ValidationErrorResponse($e->errors());
        }

        try {
            $collection = Collection::create([
                'name'         => $validated['name'],
                'description'  => $validated['description'],
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

            $input = CollectionStatus::tryFromName($status) ?? CollectionStatus::ACTIVE;
            $collections = Collection::where('status', $input->value)
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
