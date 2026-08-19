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

/**
 * Builds the Term Directory listing: OMOP concept availability aggregated across the
 * federation's latest distributions, scoped to the caller's visible collections.
 *
 * Reads the materialised `latest_distributions_materialised` snapshot (see
 * LatestDistribution / RefreshLatestDistributionsView) via a two-phase paginated
 * aggregation:
 *  - Phase A picks the page and total in one cheap streaming pass.
 *  - Phase B computes the expensive per-group aggregates for only the page's concepts.
 */
class TermDirectoryService
{
    /**
     * Paginated, searchable/filterable/sortable concept listing for the given request.
     */
    public function search(Request $request, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        $collectionIds = $this->resolveCollectionIds($request);

        // Columns that define one aggregated concept row.
        $groupKeys = ['concept_id', 'concept_name', 'domain_id'];

        // Fresh builder per use — the search/filter scopes mutate the query.
        $base = fn () => LatestDistribution::whereIn('collection_id', $collectionIds)
            ->searchViaRequest()
            ->filterViaRequest();

        // Phase A — pick the page and total in one cheap pass: SUM is streaming and
        // COUNT(*) OVER() gives the group total without a second query. The expensive
        // DISTINCT/GROUP_CONCAT aggregates are deferred to phase B (page concepts only).
        // ncollections is one of those, so we only compute it here when it's the sort
        // column (ORDER BY can only reference a column selected in this query).
        $sortField = strtolower(explode(':', (string) $request->query('sort'))[0]);

        $selectA = array_merge($groupKeys, [
            DB::raw('SUM(`count`) AS `count`'),
            DB::raw('COUNT(*) OVER () AS total_groups'),
        ]);

        if ($sortField === 'ncollections') {
            $selectA[] = DB::raw('COUNT(DISTINCT collection_id) AS ncollections');
        }

        $pageRows = $base()
            ->select($selectA)
            ->groupBy($groupKeys)
            ->applySorting('count', 'desc')
            ->forPage($page, $perPage)
            ->get();

        $total = $pageRows->isNotEmpty()
            ? (int) $pageRows->first()->getAttribute('total_groups')
            : $this->countGroups($base(), $groupKeys);

        // Phase B — the expensive aggregates for just this page's concepts, merged
        // back onto the phase-A rows by group key.
        $detail = $this->pageDetail($base(), $pageRows, $groupKeys);

        $rows = $pageRows->map(function ($row) use ($detail) {
            $extra = $detail->get($this->termGroupKey($row));

            return [
                'concept_id' => (int) $row->concept_id,
                'concept_name' => $row->concept_name,
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
     * The collection ids the current user can see, with no further narrowing.
     */
    private function visibleCollectionIds(): SupportCollection
    {
        return Collection::visibleToUser(User::find(Auth::id()))->pluck('id');
    }

    /**
     * The user's visible collections, optionally narrowed to requested public pids.
     * Pids outside the visible set are dropped so they can never widen access.
     */
    private function resolveCollectionIds(Request $request): SupportCollection
    {
        $visible = $this->visibleCollectionIds();

        $requestedPids = (array) $request->input('collection_pid', []);
        if (empty($requestedPids)) {
            return $visible;
        }

        $requested = Collection::whereIn('pid', $requestedPids)->pluck('id');

        return $visible->intersect($requested)->values();
    }

    /**
     * Concept options (concept_id, concept_name, domain_id) reported by the
     * collections the current user can see, restricted to the given OMOP
     * domains. Backs dynamic demographic pick-lists (Gender, Race) instead of
     * a hardcoded concept map.
     *
     * @param  list<string>  $domainIds
     * @param  list<int>  $collectionIds  optional further narrowing; empty
     *         means "every collection visible to the user". Never widens
     *         visibility beyond what the user can see.
     */
    public function conceptOptionsForDomains(array $domainIds, array $collectionIds = []): SupportCollection
    {
        $visible = $this->visibleCollectionIds();
        $scoped = empty($collectionIds) ? $visible : $visible->intersect($collectionIds)->values();

        if ($scoped->isEmpty() || empty($domainIds)) {
            return collect();
        }

        return LatestDistribution::query()
            ->select(['concept_id', 'concept_name', 'domain_id'])
            ->whereIn('collection_id', $scoped)
            ->whereIn('domain_id', $domainIds)
            ->distinct()
            ->get();
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
     * Stable key matching a page row to its phase-B detail row. Uses a non-printable
     * separator so concept names cannot collide.
     */
    private function termGroupKey(object $row): string
    {
        return implode("\x1f", [$row->concept_id, $row->concept_name, $row->domain_id]);
    }

    /**
     * Number of distinct concept groups matching the filters — the pagination total.
     * Only used as a fallback when the current page is empty (so the window-function
     * total on the page rows is unavailable).
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
