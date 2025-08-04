<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;

use App\Traits\Responses;
use App\Traits\HelperFunctions;



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
}
