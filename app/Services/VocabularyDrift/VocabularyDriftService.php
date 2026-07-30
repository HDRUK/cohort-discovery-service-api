<?php

namespace App\Services\VocabularyDrift;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Measures vocabulary drift per collection: how often the custodian-reported
 * domain (reported_domain_id) disagrees with the current central OMOP
 * classification (central_domain_id) for the concepts in a collection's latest
 * scan. A high mismatch rate is a signal that a custodian is running a stale
 * vocabulary and should re-ETL / refresh.
 *
 * All figures are read from the `latest_distributions` view, which is already
 * scoped to each collection's latest successful concept result file.
 */
class VocabularyDriftService
{
    private const VIEW = 'latest_distributions';

    /**
     * Per-collection drift summary. Optionally scope to specific collection ids.
     *
     * @param  array<int>|null  $collectionIds
     * @return Collection<int, array{collection_id:int,total_concepts:int,mismatched_concepts:int,mismatch_rate:float}>
     */
    public function summary(?array $collectionIds = null): Collection
    {
        if ($collectionIds !== null && count($collectionIds) === 0) {
            return collect();
        }

        $where = '';
        $bindings = [];

        if ($collectionIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($collectionIds), '?'));
            $where = "WHERE collection_id IN ({$placeholders})";
            $bindings = $collectionIds;
        }

        $rows = DB::select("
            SELECT
                collection_id,
                COUNT(DISTINCT concept_id) AS total_concepts,
                COUNT(DISTINCT CASE WHEN domain_mismatch = 1 THEN concept_id END) AS mismatched_concepts
            FROM ".self::VIEW."
            {$where}
            GROUP BY collection_id
            ORDER BY collection_id
        ", $bindings);

        return collect($rows)->map(function ($row) {
            $total = (int) $row->total_concepts;
            $mismatched = (int) $row->mismatched_concepts;

            return [
                'collection_id' => (int) $row->collection_id,
                'total_concepts' => $total,
                'mismatched_concepts' => $mismatched,
                'mismatch_rate' => $total > 0 ? round($mismatched / $total, 4) : 0.0,
            ];
        });
    }

    /**
     * The mismatched concepts for a single collection.
     *
     * @return Collection<int, array{concept_id:int,concept_name:string,reported_domain:string,central_domain:string}>
     */
    public function details(int $collectionId): Collection
    {
        $rows = DB::select("
            SELECT DISTINCT
                concept_id,
                concept_name,
                reported_domain_id AS reported_domain,
                central_domain_id AS central_domain
            FROM ".self::VIEW."
            WHERE collection_id = ?
              AND domain_mismatch = 1
            ORDER BY concept_id
        ", [$collectionId]);

        return collect($rows)->map(fn ($row) => [
            'concept_id' => (int) $row->concept_id,
            'concept_name' => (string) $row->concept_name,
            'reported_domain' => (string) $row->reported_domain,
            'central_domain' => (string) $row->central_domain,
        ]);
    }

    /**
     * Full summary + mismatch list for one collection (used by the API endpoint).
     *
     * @return array{collection_id:int,total_concepts:int,mismatched_concepts:int,mismatch_rate:float,mismatches:array<int,array<string,mixed>>}
     */
    public function report(int $collectionId): array
    {
        $summary = $this->summary([$collectionId])->first() ?? [
            'collection_id' => $collectionId,
            'total_concepts' => 0,
            'mismatched_concepts' => 0,
            'mismatch_rate' => 0.0,
        ];

        $summary['mismatches'] = $this->details($collectionId)->all();

        return $summary;
    }
}
