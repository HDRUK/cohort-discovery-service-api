<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModelBackedRequest;
use App\Models\Collection;
use App\Models\Query;
use App\Enums\QueryType;
use App\Traits\JobCreation;
use App\Traits\Responses;
use Illuminate\Http\JsonResponse;

class DistributionController extends Controller
{
    use JobCreation;
    use Responses;


    public function manuallyTriggeredRun(ModelBackedRequest $request, string $collectionPid): JsonResponse
    {
        try {
            $collection = Collection::where('pid', $collectionPid)->first();
            if (!$collection) {
                return $this->NotFoundResponse();
            }
            $queryType = $request->validated('query_type');
            $queryTypeEnum = QueryType::from($queryType);

            $query = Query::createDistributionQuery($collection, $queryTypeEnum);

            return $this->OKResponse($query);

        } catch (\Throwable $e) {
            return $this->ErrorResponse($e->getMessage());
        }
    }
}
