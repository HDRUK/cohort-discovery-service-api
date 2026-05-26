<?php

namespace App\Services\NLP;

use App\Services\ConceptSearchService;

class ConceptCandidateResolver
{
    public function __construct(
        private readonly ConceptSearchService $conceptSearchService
    ) {
    }

    public function resolveGroup(string $textKey, array $candidates): array
    {
        $primary = $candidates[0] ?? [];
        $terms = $this->candidateSearchTerms($textKey, $primary);

        foreach ($terms as $term) {
            // Final rule concepts are resolved through the shared API search service so free-text queries and direct concept searches use the same ranking rules.
            $results = $this->conceptSearchService->resolveTerm($term, limit: 10);

            if (! empty($results)) {
                return array_map(
                    fn (array $result) => $this->conceptSearchResultToCandidate($primary, $result),
                    $results
                );
            }
        }

        return $candidates;
    }

    private function candidateSearchTerms(string $textKey, array $candidate): array
    {
        $terms = [];
        $text = trim((string) ($candidate['text'] ?? $textKey));

        if ($text !== '') {
            $terms[] = $text;
        }

        // The exact phrase found in the query is tried first, followed by NLP-normalised names and descriptions as fallbacks.
        foreach (['concept_name', 'description'] as $attribute) {
            $term = trim((string) ($candidate['attributes'][$attribute] ?? ''));
            if ($term !== '') {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }

    private function conceptSearchResultToCandidate(array $sourceCandidate, array $result): array
    {
        $attributes = $sourceCandidate['attributes'] ?? [];

        // Structural NLP metadata is preserved while concept fields are replaced with the API-ranked result used to build the final rule.
        return [
            'text' => $sourceCandidate['text'] ?? $result['name'],
            'label' => $sourceCandidate['label'] ?? null,
            'start' => $sourceCandidate['start'] ?? 0,
            'end' => $sourceCandidate['end'] ?? strlen((string) ($sourceCandidate['text'] ?? $result['name'])),
            'negated' => $sourceCandidate['negated'] ?? false,
            'age_constraints' => $sourceCandidate['age_constraints'] ?? [],
            'time_constraints' => $sourceCandidate['time_constraints'] ?? [],
            'attributes' => array_merge($attributes, [
                'concept_id' => $result['concept_id'],
                'concept_name' => $result['name'],
                'description' => $result['description'] ?? $result['name'],
                'domain_id' => $result['category'],
                'ncollections' => $result['ncollections'] ?? 0,
                'all_synthetic' => $result['all_synthetic'] ?? 0,
                'match_score' => $result['match_score'] ?? 0,
                'tokens' => $attributes['tokens'] ?? [],
                'phrase_tokens' => $attributes['phrase_tokens'] ?? [],
                'unmatched' => false,
            ]),
        ];
    }
}
