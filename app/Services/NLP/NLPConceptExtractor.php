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

    public function extract(string $query, float $threshold = 50): array
    {
        $response = Http::post("{$this->baseUri}/extract?threshold={$threshold}", [
            'query' => $query,
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
