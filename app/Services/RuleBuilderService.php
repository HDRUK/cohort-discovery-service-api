<?php

namespace App\Services;

use App\Traits\RuleBuilder;
use App\Traits\ConceptLookup;
use App\Traits\NLPConceptLookup;
use Illuminate\Support\Str;

/**
 * RuleBuilderService parses a natural language query string into a structured array of rules.
 *
 * Supports:
 * - Logical combinators (and, or, followed_by)
 * - Grouping via parentheses
 * - Exclusion terms (not, without, excluding)
 */
class RuleBuilderService
{
    use RuleBuilder;
    use ConceptLookup;
    use NLPConceptLookup;

    /** @var array<string> Logical combinators supported in queries */
    private array $combinators = ['and', 'or', 'followed', 'followed_by', '(', ')',];

    /** @var array<string> Terms that indicate exclusion/negation in queries */
    private array $exclusionTerms = ['not', 'without', 'excluding'];

    private function splitTopLevelOr(string $query): array
    {
        $q = strtolower($query);
        $segments = preg_split('/\s+or\s+/', $q);

        return array_map('trim', $segments);
    }

    private function getConceptsForSegment(string $segment): array
    {
        $this->loadNlpEntities($segment);

        $rules = [];
        foreach ($this->nlpEntities as $ent) {
            $rules[] = $this->makeRule([
                'concept_id' => $ent['attributes']['concept_id'],
                'description' => $ent['attributes']['description'],
                'category' => $ent['attributes']['domain_id'],
                'children' => [],
            ]);
        }

        return $rules;
    }

    /**
     * Parses a query string into a structured rules array.
     */
    public function parseToRules(string $query): array
    {
        $segments = $this->splitTopLevelOr($query);
        
        $rules = [];
        foreach ($segments as $i => $segment) {
            \Log::info('Finding OMOP concepts for segment: ' . $segment);
            $concepts = $this->getConceptsForSegment($segment);

            // If there are multiple concepts, wrap in AND group
            if (count($concepts) > 1) {
                // Inject AND combinators between multiple concepts
                $groupNodes = [];

                foreach ($concepts as $index => $c) {
                    if ($index > 0) {
                        $groupNodes[] = $this->makeOperator('and');
                    }
                    $groupNodes[] = $c;
                }

                $rules[] = $this->makeGroup($groupNodes);
            } else if (count($concepts) === 1) {
                $rules[] = $concepts[0];
            }

            // Inject OR between top level segments
            if ($i < count($concepts) - 1) {
                $rules[] = $this->makeOperator('or');
            }
        }

        return [
            'id' => Str::uuid()->toString(),
            'rules' => $rules,
            'valid' => true,
        ];
    }
}
