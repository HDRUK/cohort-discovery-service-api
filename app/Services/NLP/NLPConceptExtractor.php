<?php

namespace App\Services\NLP;

use App\Models\NlpQueryLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class NLPConceptExtractor
{
    protected string $baseUri;

    public function __construct()
    {
        $this->baseUri = config('services.nlp.base_uri');
    }

    public function searchConcepts(array $params): array
    {
        $response = Http::timeout(30)->post("{$this->baseUri}/concepts/search", $params);

        if (! $response->successful()) {
            throw new \RuntimeException('NLP concept search failed: '.$response->body());
        }

        return $response->json();
    }

    public function extract(
        string $query,
        float $threshold = 50,
        int $max_matches = 10,
        bool $useStatsOrdering = false,
        bool $useCollectionFilter = false,
        array $collectionIds = [],
    ): array {
        $response = Http::post("{$this->baseUri}/extract?threshold={$threshold}&max_matches={$max_matches}", [
            'query'                => $query,
            'use_stats_ordering'   => $useStatsOrdering,
            'use_collection_filter'=> $useCollectionFilter,
            'collection_ids'       => $collectionIds ?: null,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('NLP extraction failed: '.$response->body());
        }

        // dd($response->json());

        $payload = $response->json();

        NlpQueryLog::create([
            'query' => $query,
            'nlp_extracted' => json_encode($payload['entities'] ?? []),
            'user_id' => 0, // TODO - Add Auth::id() - haven't because it's not
            // passed through as-is for some reason - to investigate
        ]);

        return $payload;
    }
}
