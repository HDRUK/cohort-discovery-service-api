<?php

namespace App\Traits;

use App\Services\NLPConceptExtractor;
use Illuminate\Support\Facades\App;

trait NLPConceptLookup
{
    protected ?array $nlpEntities = null;

    protected function loadNlpEntities(string $query, float $threshold = 80): void
    {
        \Log::info('Calling NLP Extractor with: '.$query);
        $nlp = App::make(NLPConceptExtractor::class);
        $this->nlpEntities = collect($nlp->extract($query, $threshold))
            ->mapWithKeys(fn ($e) => [strtolower(trim($e['text'])) => $e])
            ->toArray();
    }

    protected function lookupConceptFromNlp(string $phrase): ?array
    {
        if (! $this->nlpEntities) {
            return null;
        }

        $found = null;
        $key = strtolower(trim($phrase));
        if (! isset($this->nlpEntities[$key])) {
            // fuzzy fallback - find similar keys
            $found = collect($this->nlpEntities)
                ->first(fn ($entity, $text) => levenshtein($key, $text) < 5);

            if (! $found) {
                return null;
            }
        } else {
            $found = $this->nlpEntities[$key];
        }

        $attr = $found['attributes'] ?? [];

        return [
            'concept_id' => $attr['concept_id'] ?? null,
            'concept_name' => $attr['concept_name'] ?? ucfirst($found['text']),
            'domain_id' => $attr['domain_id'] ?? 'Condition',
            'children' => [
                // TODO
            ],
        ];
    }
}
