<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Distribution;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Http\Request;


class CodeController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getAllCodes(Request $request)
    {
        $collectionPids = $request->input('collections');
        $collectionsQuery = Collection::query();

        if (is_array($collectionPids) && count($collectionPids)) {
            $collectionsQuery->whereIn('pid', $collectionPids);
        }

        $collectionIds = $collectionsQuery->pluck('id');
        $codes = Distribution::whereIn('collection_id', $collectionIds)
            ->select('name', 'description', 'category')
            ->distinct()
            ->get();

        return $this->OKResponse($codes);
    }

    public function getCodes(Request $request, string $domain)
    {
        $collectionPids = $request->input('collections');
        $collectionsQuery = Collection::query();

        if (is_array($collectionPids) && count($collectionPids)) {
            $collectionsQuery->whereIn('pid', $collectionPids);
        }

        $collectionIds = $collectionsQuery->pluck('id');
        $codes = Distribution::whereIn('collection_id', $collectionIds)
            ->whereRaw('LOWER(category) = ?', [strtolower($domain)])
            ->select('name', 'description')
            ->distinct()
            ->get();

        return $this->OKResponse($codes);
    }
}
