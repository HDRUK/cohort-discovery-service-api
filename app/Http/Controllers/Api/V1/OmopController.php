<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Omop\Concept;
use App\Models\Omop\ConceptAncestor;
use App\Traits\Responses;
use App\Traits\HelperFunctions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class OmopController extends Controller
{
    use Responses;
    use HelperFunctions;

    public function getRelations($concept_id)
    {
        $conn = DB::connection('omop');

        // 1) Base concept (on omop)
        $concept = $conn->table('concept')
            ->where('concept_id', $concept_id)
            ->first();

        if (!$concept) {
            return $this->NotFoundResponse("Concept {$concept_id} not found.");
        }

        // Optional filters
        $today = Carbon::today()->toDateString();
        $relationshipIds   = (array) request()->input('relationship_ids', []);
        $maxAncestorLevel  = request()->integer('max_ancestor_level', 2);
        $maxDescendantLevel = request()->integer('max_descendant_level', 2);
        $relLimit  = request()->integer('limit', 100);
        $relOffset = request()->integer('offset', 0);

        // 2) Relationships OUT
        $relsOut = $conn->table('concept_relationship as cr')
            ->join('concept as c', 'c.concept_id', '=', 'cr.concept_id_2')
            ->where('cr.concept_id_1', $concept_id)
            //->whereNull('cr.invalid_reason')
            ->where('cr.valid_start_date', '<=', $today)
            ->where('cr.valid_end_date', '>=', $today)
            ->when(!empty($relationshipIds), fn($q) => $q->whereIn('cr.relationship_id', $relationshipIds))
            ->selectRaw("? as direction, cr.relationship_id, cr.concept_id_1, cr.concept_id_2 as related_concept_id, cr.valid_start_date, cr.valid_end_date, c.concept_name as related_concept_name, c.domain_id as related_domain_id, c.vocabulary_id as related_vocabulary_id, c.concept_class_id as related_concept_class_id, c.invalid_reason as related_invalid_reason", ['out']);

        // 3) Relationships IN
        $relsIn = $conn->table('concept_relationship as cr')
            ->join('concept as c', 'c.concept_id', '=', 'cr.concept_id_1')
            ->where('cr.concept_id_2', $concept_id)
            //->whereNull('cr.invalid_reason')
            ->where('cr.valid_start_date', '<=', $today)
            ->where('cr.valid_end_date', '>=', $today)
            ->when(!empty($relationshipIds), fn($q) => $q->whereIn('cr.relationship_id', $relationshipIds))
            ->selectRaw("? as direction, cr.relationship_id, cr.concept_id_2, cr.concept_id_1 as related_concept_id, cr.valid_start_date, cr.valid_end_date, c.concept_name as related_concept_name, c.domain_id as related_domain_id, c.vocabulary_id as related_vocabulary_id, c.concept_class_id as related_concept_class_id, c.invalid_reason as related_invalid_reason", ['in']);

        // IMPORTANT: build the UNION on the same connection, and wrap with fromSub using that connection
        $union = $relsOut->unionAll($relsIn);

        $relationships = $conn->query()            // <- use the omop connection here
            ->fromSub($union, 'rel')
            ->orderBy('relationship_id')
            ->orderBy('direction')
            ->orderBy('related_concept_id')
            ->offset($relOffset)
            ->limit($relLimit)
            ->get();

        // 4) Ancestors
        $ancestorsQ = $conn->table('concept_ancestor as ca')
            ->join('concept as c', 'c.concept_id', '=', 'ca.ancestor_concept_id')
            ->where('ca.descendant_concept_id', $concept_id)
            ->select([
                'ca.ancestor_concept_id as concept_id',
                'c.concept_name',
                'c.domain_id',
                'c.vocabulary_id',
                'c.concept_class_id',
                'c.invalid_reason',
                'ca.min_levels_of_separation',
                'ca.max_levels_of_separation',
            ])
            ->orderBy('ca.min_levels_of_separation');

        if (!is_null($maxAncestorLevel)) {
            $ancestorsQ->where('ca.min_levels_of_separation', '<=', $maxAncestorLevel);
        }
        $ancestors = $ancestorsQ->get();

        // 5) Descendants
        $descendantsQ = $conn->table('concept_ancestor as ca')
            ->join('concept as c', 'c.concept_id', '=', 'ca.descendant_concept_id')
            ->where('ca.ancestor_concept_id', $concept_id)
            ->select([
                'ca.descendant_concept_id as concept_id',
                'c.concept_name',
                'c.domain_id',
                'c.vocabulary_id',
                'c.concept_class_id',
                'c.invalid_reason',
                'ca.min_levels_of_separation',
                'ca.max_levels_of_separation',
            ])
            ->orderBy('ca.min_levels_of_separation');

        if (!is_null($maxDescendantLevel)) {
            $descendantsQ->where('ca.min_levels_of_separation', '<=', $maxDescendantLevel);
        }
        $descendants = $descendantsQ->get();

        return $this->OKResponse([
            'concept'       => $concept,
            'relationships' => $relationships,
            'ancestors'     => $ancestors,
            'descendants'   => $descendants,
            'meta' => [
                'filters' => [
                    'relationship_ids' => $relationshipIds,
                    'max_ancestor_level' => $maxAncestorLevel,
                    'max_descendant_level' => $maxDescendantLevel,
                ],
                'pagination' => ['limit' => $relLimit, 'offset' => $relOffset],
                'as_of_date' => $today,
            ],
        ]);
    }

    public function getPeersAtLevel($concept_id)
    {
        $nup           = max(1, (int) request()->input('max_up', 1));
        $ndown           = max(1, (int) request()->input('max_down', 1));
        $standardOnly = request()->boolean('standard_only', false);
        $sameDomain   = request()->boolean('same_domain', false);
        $sameVocab    = request()->boolean('same_vocabulary', false);

        $start = null;
        if ($sameDomain || $sameVocab) {
            $start = Concept::findOrFail($concept_id);
        }

        $ancestors = ConceptAncestor::where(['descendant_concept_id' => $concept_id])
            ->where('min_levels_of_separation', '>', 0)
            ->where('max_levels_of_separation', '<', $nup + 1)
            ->pluck('ancestor_concept_id');

        $desc = ConceptAncestor::whereIn('ancestor_concept_id', $ancestors)
            ->where('min_levels_of_separation', '>', 0)
            ->where('max_levels_of_separation', '<', $ndown + 1)
            ->when($standardOnly, function ($query) {
                $query->whereHas('descendant', function ($q) {
                    $q->where('standard_concept', 'S');
                });
            })
            ->when($sameDomain && $start, function ($q) use ($start) {
                $q->whereHas('descendant', fn($c) => $c->where('domain_id', $start->domain_id));
            })
            ->when($sameVocab && $start, function ($q) use ($start) {
                $q->whereHas('descendant', fn($c) => $c->where('vocabulary_id', $start->vocabulary_id));
            })
            ->with('descendant')
            ->get();


        return response()->json($desc);
    }

    public function getPeersAtLevel2($ancestors)
    {
        $conn = DB::connection('omop');

        // ---- perf helpers -------------------------------------------------------
        $t0 = microtime(true);
        $lap = function (&$perf, string $key, float &$mark) {
            $now = microtime(true);
            $perf['steps'][$key . '_ms'] = round(1000 * ($now - $mark), 1);
            $mark = $now;
        };
        $perf = ['steps' => [], 'counts' => []];

        // (optional) capture per-query timings (skip in prod if you want)
        $captureQueries = config('app.debug', false);
        if ($captureQueries) {
            $conn->enableQueryLog();
        }

        try {
            // 0) Start concept
            $mark = microtime(true);
            $start = $conn->table('concept')
                ->select('concept_id', 'concept_name', 'domain_id', 'vocabulary_id', 'concept_class_id', 'standard_concept', 'invalid_reason')
                ->where('concept_id', $concept_id)
                ->first();
            $lap($perf, '0_start_lookup', $mark);

            if (!$start) {
                if ($captureQueries) {
                    $perf['db'] = $this->summarizeQueryLog($conn->getQueryLog());
                }
                $perf['total_ms'] = round(1000 * (microtime(true) - $t0), 1);
                //Log::info('getPeersAtLevelFast:not_found', ['concept_id' => $concept_id, 'perf' => $perf]);
                return $this->NotFoundResponse("Concept {$concept_id} not found.", ['perf' => $perf]);
            }

            // Params
            $up           = max(1, (int) request()->input('up', 1));
            $includeSelf  = request()->boolean('include_self', false);
            $standardOnly = request()->boolean('standard_only', false);
            $sameDomain   = request()->boolean('same_domain', false);
            $sameVocab    = request()->boolean('same_vocabulary', false);
            $limit        = (int) request()->input('limit', 500);
            $offset       = (int) request()->input('offset', 0);

            // Helpers (accept NULL or '' for invalid_reason; accept S/NULL/'' for standard)
            $valid = function ($q, $alias) {
                $q->where(function ($x) use ($alias) {
                    $x->whereNull("$alias.invalid_reason")->orWhere("$alias.invalid_reason", '');
                });
            };
            $standard = function ($q, $alias) {
                $q->where(function ($x) use ($alias) {
                    $x->where("$alias.standard_concept", 'S')
                        ->orWhereNull("$alias.standard_concept")
                        ->orWhere("$alias.standard_concept", '');
                });
            };

            // 1) Get pivot ancestor IDs exactly `up` levels above start
            $mark = microtime(true);
            $pivotIds = $conn->table('concept_ancestor')
                ->where('descendant_concept_id', $concept_id)
                ->where('min_levels_of_separation', $up)
                ->pluck('ancestor_concept_id')
                ->unique()
                ->values();
            $lap($perf, '1_fetch_pivot_ids', $mark);
            $perf['counts']['pivot_ids'] = $pivotIds->count();

            if ($pivotIds->isEmpty()) {
                $perf['total_ms'] = round(1000 * (microtime(true) - $t0), 1);
                if ($captureQueries) {
                    $perf['db'] = $this->summarizeQueryLog($conn->getQueryLog());
                }
                //Log::info('getPeersAtLevelFast:return_no_pivots', ['concept_id' => $concept_id, 'perf' => $perf]);
                return $this->OKResponse([
                    'start_concept'   => $start,
                    'up'              => $up,
                    'pivot_ancestors' => [],
                    'peers_by_pivot'  => [],
                    'meta' => array_merge(
                        compact('includeSelf', 'standardOnly', 'sameDomain', 'sameVocab', 'limit', 'offset'),
                        ['perf' => $perf]
                    ),
                ]);
            }

            // 2) Fetch pivot ancestor details
            $mark = microtime(true);
            $pivots = $conn->table('concept as anc')
                ->select('anc.concept_id', 'anc.concept_name', 'anc.domain_id', 'anc.vocabulary_id', 'anc.concept_class_id', 'anc.standard_concept', 'anc.invalid_reason')
                ->whereIn('anc.concept_id', $pivotIds)
                //->tap(fn($q) => $valid($q, 'anc'))
                ->when($standardOnly, fn($q) => $standard($q, 'anc'))
                ->get()
                ->keyBy('concept_id');
            $lap($perf, '2_fetch_pivot_details', $mark);
            $perf['counts']['pivots'] = $pivots->count();

            if ($pivots->isEmpty()) {
                $perf['total_ms'] = round(1000 * (microtime(true) - $t0), 1);
                if ($captureQueries) {
                    $perf['db'] = $this->summarizeQueryLog($conn->getQueryLog());
                }
                //Log::info('getPeersAtLevelFast:return_no_pivot_details', ['concept_id' => $concept_id, 'perf' => $perf]);
                return $this->OKResponse([
                    'start_concept'   => $start,
                    'up'              => $up,
                    'pivot_ancestors' => [],
                    'peers_by_pivot'  => [],
                    'meta' => array_merge(
                        compact('includeSelf', 'standardOnly', 'sameDomain', 'sameVocab', 'limit', 'offset'),
                        ['perf' => $perf]
                    ),
                ]);
            }

            // 3) Get (pivot_id, peer_id) pairs
            $mark = microtime(true);
            $pairs = $conn->table('concept_ancestor')
                ->select('ancestor_concept_id', 'descendant_concept_id')
                ->whereIn('ancestor_concept_id', $pivots->keys())
                ->where('min_levels_of_separation', $up)
                ->when(!$includeSelf, fn($q) => $q->where('descendant_concept_id', '!=', $concept_id))
                ->get();
            $lap($perf, '3_fetch_pairs', $mark);
            $perf['counts']['pairs'] = $pairs->count();

            if ($pairs->isEmpty()) {
                $perf['total_ms'] = round(1000 * (microtime(true) - $t0), 1);
                if ($captureQueries) {
                    $perf['db'] = $this->summarizeQueryLog($conn->getQueryLog());
                }
                //Log::info('getPeersAtLevelFast:return_no_pairs', ['concept_id' => $concept_id, 'perf' => $perf]);
                return $this->OKResponse([
                    'start_concept'   => $start,
                    'up'              => $up,
                    'pivot_ancestors' => $pivots->values(),
                    'peers_by_pivot'  => [],
                    'meta' => array_merge(
                        compact('includeSelf', 'standardOnly', 'sameDomain', 'sameVocab', 'limit', 'offset'),
                        ['perf' => $perf]
                    ),
                ]);
            }

            // 4) Fetch peer concept details
            $peerIds = $pairs->pluck('descendant_concept_id')->unique()->values();
            $perf['counts']['peer_ids'] = $peerIds->count();

            $mark = microtime(true);
            $peersQ = $conn->table('concept as peer')
                ->select('peer.concept_id', 'peer.concept_name', 'peer.domain_id', 'peer.vocabulary_id', 'peer.concept_class_id', 'peer.standard_concept', 'peer.invalid_reason')
                ->whereIn('peer.concept_id', $peerIds);
            //->tap(fn($q) => $valid($q, 'peer'));

            if ($standardOnly) {
                $peersQ->tap(fn($q) => $standard($q, 'peer'));
            }
            if ($sameDomain) {
                $peersQ->where('peer.domain_id', $start->domain_id);
            }
            if ($sameVocab) {
                $peersQ->where('peer.vocabulary_id', $start->vocabulary_id);
            }

            $peersList = $peersQ
                //->orderBy('peer.concept_name')
                //->offset($offset)
                //->limit($limit)
                ->get()
                ->keyBy('concept_id');
            $lap($perf, '4_fetch_peer_details', $mark);
            $perf['counts']['peers'] = $peersList->count();

            // 5) Group
            $mark = microtime(true);
            $byPivot = [];
            foreach ($pairs as $row) {
                $pivotId = $row->ancestor_concept_id;
                $peerId  = $row->descendant_concept_id;
                if (!isset($pivots[$pivotId]) || !isset($peersList[$peerId])) {
                    continue;
                }
                $byPivot[$pivotId] ??= [
                    'pivot_ancestor_id'   => $pivotId,
                    'pivot_ancestor_name' => $pivots[$pivotId]->concept_name,
                    'peers' => [],
                ];
                $byPivot[$pivotId]['peers'][] = $peersList[$peerId];
            }
            $peersByPivot = array_values(array_map(function ($bucket) {
                $bucket['peers'] = array_values(array_map(fn($o) => (array) $o, $bucket['peers']));
                return $bucket;
            }, $byPivot));
            $lap($perf, '5_group_results', $mark);

            // finalize perf
            $perf['total_ms'] = round(1000 * (microtime(true) - $t0), 1);
            if ($captureQueries) {
                $perf['db'] = $this->summarizeQueryLog($conn->getQueryLog());
            }

            /*Log::info('getPeersAtLevelFast:ok', [
                'concept_id' => $concept_id,
                'params'     => compact('up', 'includeSelf', 'standardOnly', 'sameDomain', 'sameVocab', 'limit', 'offset'),
                'perf'       => $perf,
            ]);*/

            return $this->OKResponse([
                'start_concept'   => $start,
                'up'              => $up,
                'pivot_ancestors' => $pivots->values(),
                'peers_by_pivot'  => $peersByPivot,
                'meta' => [
                    'include_self'    => $includeSelf,
                    'standard_only'   => $standardOnly,
                    'same_domain'     => $sameDomain,
                    'same_vocabulary' => $sameVocab,
                    'pagination'      => compact('limit', 'offset'),
                    'perf'            => $perf,
                ],
            ]);
        } finally {
            // turn off query log to avoid memory bloat on long-lived processes
            if ($captureQueries) {
                $conn->flushQueryLog();
            }
        }
    }

    /**
     * Summarize the connection query log for inclusion in meta.
     * Each entry typically has: ['query' => ..., 'bindings' => [...], 'time' => ms]
     */
    protected function summarizeQueryLog(array $log): array
    {
        if (empty($log)) {
            return ['query_count' => 0, 'total_query_time_ms' => 0.0];
        }

        $total = 0.0;
        $slowest = null;
        $slowestMs = -1.0;
        $topN = [];
        foreach ($log as $i => $q) {
            $ms = isset($q['time']) ? (float) $q['time'] : 0.0;
            $total += $ms;
            if ($ms > $slowestMs) {
                $slowestMs = $ms;
                $slowest = $q['query'];
            }
        }

        // include first few SQLs to spot culprits without overwhelming the payload
        foreach (array_slice($log, 0, 5) as $q) {
            $topN[] = [
                'sql'  => $q['query'],
                'ms'   => isset($q['time']) ? (float) $q['time'] : null,
            ];
        }

        return [
            'query_count'         => count($log),
            'total_query_time_ms' => round($total, 1),
            'slowest_query_ms'    => round(max(0, $slowestMs), 1),
            'slowest_sql'         => $slowest,
            'sample'              => $topN,
        ];
    }
}
