<?php

namespace App\Services\TermDirectory;

use App\Models\Collection;
use App\Models\LatestDistribution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the Term Directory listing: OMOP concept availability aggregated across the
 * federation's latest distributions, scoped to the caller's visible collections.
 *
 * Reads the materialised `latest_distributions_materialised` snapshot (see
 * LatestDistribution / RefreshLatestDistributionsView) via a two-phase paginated
 * aggregation:
 *  - Phase A picks the page in one cheap streaming pass.
 *  - Phase B computes the expensive per-group aggregates for only the page's concepts.
 */
class TermDirectoryService
{
    /**
     * Paginated, searchable/filterable/sortable concept listing for the given request.
     */
    public function search(Request $request, int $perPage): LengthAwarePaginator
    {
        $startedAt = hrtime(true);

        $page = LengthAwarePaginator::resolveCurrentPage();

        $collectionIds = $this->resolveCollectionIds($request);

        // Columns that define one aggregated concept row.
        $groupKeys = ['concept_id', 'domain_id'];

        // Fresh builder per use — the search/filter scopes mutate the query.
        $base = fn () => LatestDistribution::whereIn('collection_id', $collectionIds)
            ->where('count', '>', 0)
            ->searchViaRequest()
            ->filterViaRequest();

        // Phase A — pick the page in one cheap pass: SUM is streaming. The expensive
        // DISTINCT/GROUP_CONCAT aggregates are deferred to phase B (page concepts only).
        // ncollections is one of those, so we only compute it here when it's the sort
        // column (ORDER BY can only reference a column selected in this query).
        $sortField = strtolower(explode(':', (string) $request->query('sort'))[0]);

        $selectA = array_merge($groupKeys, [
            DB::raw('SUM(`count`) AS `count`'),
        ]);

        if ($sortField === 'ncollections') {
            $selectA[] = DB::raw('COUNT(DISTINCT collection_id) AS ncollections');
        }

        if ($sortField === 'concept_name') {
            $selectA[] = DB::raw('MAX(concept_name) AS concept_name');
        }

        $phaseAStartedAt = hrtime(true);

        $pageRows = $base()
            ->select($selectA)
            ->groupBy($groupKeys)
            ->applySorting('count', 'desc')
            ->orderBy('concept_id')
            ->orderBy('domain_id')
            ->forPage($page, $perPage)
            ->get();

        $countStartedAt = hrtime(true);

        $total = $this->countGroups($base(), $groupKeys);

        $phaseBStartedAt = hrtime(true);

        // Phase B — the expensive aggregates for just this page's concepts, merged
        // back onto the phase-A rows by group key.
        $detail = $this->pageDetail($base(), $pageRows, $groupKeys);

        $finishedAt = hrtime(true);

        Log::info('term directory search timings', [
            'phase_a_ms' => $this->elapsedMs($phaseAStartedAt, $countStartedAt),
            'count_ms' => $this->elapsedMs($countStartedAt, $phaseBStartedAt),
            'phase_b_ms' => $this->elapsedMs($phaseBStartedAt, $finishedAt),
            'total_ms' => $this->elapsedMs($startedAt, $finishedAt),
            'collections' => $collectionIds->count(),
            'has_search' => $request->hasAny(['concept_name', 'concept_id']),
            'has_filter' => $request->hasAny(['domain_id', 'domain_id__in']),
            'sort' => $request->query('sort'),
            'page' => $page,
        ]);

        $rows = $pageRows->map(function ($row) use ($detail) {
            $extra = $detail->get($this->termGroupKey($row));

            return [
                'concept_id' => (int) $row->concept_id,
                'concept_name' => $extra?->getAttribute('concept_name'),
                'domain_id' => $row->domain_id,
                'central_domain_id' => $extra?->getAttribute('central_domain_id'),
                'reported_domains' => $extra?->getAttribute('reported_domains') ?? [],
                'domain_mismatch' => (bool) $extra?->getAttribute('domain_mismatch'),
                'count' => (int) $row->count,
                'ncollections' => (int) $extra?->getAttribute('ncollections'),
            ];
        })->all();

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    /**
     * The user's visible collections, optionally narrowed to requested public pids.
     * Pids outside the visible set are dropped so they can never widen access.
     */
    private function resolveCollectionIds(Request $request): SupportCollection
    {
        $visible = Collection::visibleToUser(User::find(Auth::id()))->pluck('id');

        $requestedPids = (array) $request->input('collection_pid', []);
        if (empty($requestedPids)) {
            return $visible;
        }

        $requested = Collection::whereIn('pid', $requestedPids)->pluck('id');

        return $visible->intersect($requested)->values();
    }

    /**
     * The expensive per-group aggregates (DISTINCT count, MAX, GROUP_CONCAT), restricted
     * to the concepts on the current page (~25) and keyed by termGroupKey. Same filters
     * and group keys as phase A, so every page row's group is present.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $groupKeys
     */
    private function pageDetail($query, SupportCollection $pageRows, array $groupKeys): SupportCollection
    {
        $conceptIds = $pageRows->pluck('concept_id')->unique()->values()->all();

        if (empty($conceptIds)) {
            return collect();
        }

        return $query
            ->select(array_merge($groupKeys, [
                DB::raw('MAX(concept_name) AS concept_name'),
                DB::raw('COUNT(DISTINCT collection_id) AS ncollections'),
                DB::raw('MAX(central_domain_id) AS central_domain_id'),
                DB::raw('MAX(domain_mismatch) AS domain_mismatch'),
                DB::raw('GROUP_CONCAT(DISTINCT reported_domain_id ORDER BY reported_domain_id) AS reported_domains'),
            ]))
            ->whereIn('concept_id', $conceptIds)
            ->groupBy($groupKeys)
            ->get()
            ->keyBy(fn ($row) => $this->termGroupKey($row));
    }

    /**
     * Stable key matching a page row to its phase-B detail row.
     */
    private function termGroupKey(object $row): string
    {
        return implode("\x1f", [$row->concept_id, $row->domain_id]);
    }

    private function elapsedMs(int $from, int $to): float
    {
        return round(($to - $from) / 1e6, 1);
    }

    /**
     * Number of distinct concept groups matching the filters — the pagination total.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  list<string>  $groupKeys
     */
    private function countGroups($query, array $groupKeys): int
    {
        return DB::query()
            ->fromSub($query->select($groupKeys)->groupBy($groupKeys), 'groups')
            ->count();
    }
}
