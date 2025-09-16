<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Custodian;
use App\Services\QueryContext\QueryContextType;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


class CollectionController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getCollection($pid)
    {
        $collection = Collection::where('pid', $pid)
            ->with('size')
            ->first();

        if (!$collection) {
            return $this->NotFoundResponse();
        }

        return $this->OKResponse($collection);
    }

    public function getCollections()
    {
        $collections = Collection::with('demographics')->get();

        return $this->OKResponse($collections);
    }

    public function indexByCustodian(Request $request, string $custodianPid)
    {
        $custodian = Custodian::where('pid', $custodianPid)->first();
        //gate
        $perPage = $this->resolvePerPage();

        $collections = Collection::query()
            ->with(['host']) #, 'size', 'demographics', 'codes'])
            ->where('custodian_id', $custodian->id)
            ->get();
        //    ->paginate($perPage);

        return $this->OKResponse($collections);
    }

    public function storeByCustodian(Request $request, string $custodianPid)
    {
        $custodian = Custodian::where('pid', $custodianPid)->first();
        try {
            $validated = $request->validate([
                'name'    => ['required', 'string', 'max:255'],
                //'description'    => ['required', 'string', 'max:255'],
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
}
