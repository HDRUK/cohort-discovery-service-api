<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Distribution;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


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

    public function getCodeStats(Request $request)
    {
        $perPage = $this->resolvePerPage();
        $totalCollections = Collection::count();
        $collectionPid = $request->input('collection_pid');

        $codes = Distribution::query()
            ->whereRaw("name REGEXP '^[0-9]+$'")
            ->where("concept_id", ">", 0)
            ->whereNotNull('concept_id')
            ->when($collectionPid, function ($query, $collectionPid) {
                $query->where('collection_id', $collectionPid);
            })
            ->select([
                'name',
                'description',
                'category',
                DB::raw('COUNT(DISTINCT collection_id) AS collections_count'),
                DB::raw('ROUND(COUNT(DISTINCT collection_id) * 100.0 / ' . (int) $totalCollections . ', 2) AS collections_pct'),
                DB::raw('SUM(`count`) AS total_count')
            ])
            ->groupBy('name', 'description', 'category')
            ->orderByDesc('collections_count')
            ->paginate($perPage);

        return $this->OKResponse($codes);
    }

    public function getCollectionCodeStats(Request $request, string $collectionPid)
    {
        try {
            $perPage = $this->resolvePerPage();

            $codes = Distribution::query()
                ->whereRaw("name REGEXP '^[0-9]+$'")
                ->where("concept_id", ">", 0)
                ->whereNotNull('concept_id')
                ->where('collection_id', Collection::where(['pid' => $collectionPid])->first()->id)
                ->select([
                    'name',
                    'description',
                    'category',
                    'count'
                ])
                ->orderByDesc('count')
                ->paginate($perPage);

            return $this->OKResponse($codes);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage());
        }
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
