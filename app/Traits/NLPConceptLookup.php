<?php

namespace App\Traits;

use Illuminate\Support\Facades\App;

trait NLPConceptLookup
{
    protected ?array $nlpEntities = null;
    protected ?array $nlpGroups = null;
    protected ?string $nlpRootOperator = null;
    protected array $nlpRootGroups = [];
    protected array $nlpRootAgeConstraints = [];
    protected array $nlpRootTimeConstraints = [];
    protected array $nlpWarnings = [];

    protected function loadNlpEntities(string $query, float $threshold = 80, array $collectionIds = []): void
    {
        \Log::info('Calling NLP Extractor with: "'.$query.'"');

        $nlp = App::make(\App\Services\NLP\NLPConceptExtractor::class);

        $payload = $nlp->extract($query, $threshold, 10, $collectionIds);
        \Log::info(json_encode(collect($payload)));

        $entities = $payload['entities'] ?? $payload;
        $this->nlpGroups = $payload['groups'] ?? [];
        $this->nlpRootOperator = $payload['root_operator'] ?? null;
        $this->nlpRootGroups = $payload['root_groups'] ?? [];
        $this->nlpRootAgeConstraints = $payload['age_constraints'] ?? [];
        $this->nlpRootTimeConstraints = $payload['time_constraints'] ?? [];
        $this->nlpWarnings = $payload['warnings'] ?? [];

        $this->nlpEntities = collect($entities)
            ->groupBy(fn ($e) => strtolower(trim($e['text'] ?? '')))
            ->map(fn ($group) => $group->values()->all())
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
