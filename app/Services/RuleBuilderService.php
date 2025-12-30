<?php

namespace App\Services;

use App\Traits\NLPConceptLookup;
use App\Traits\RuleBuilder;
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
    use NLPConceptLookup;
    use RuleBuilder;

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

        foreach (($this->nlpEntities ?? []) as $textKey => $candidates) {
            if (empty($candidates)) {
                continue;
            }

            usort($candidates, function ($a, $b) {
                $sa = $a['attributes']['match_score'] ?? 0;
                $sb = $b['attributes']['match_score'] ?? 0;
                return $sb <=> $sa;
            });

            $primary = $candidates[0];
            $alts = array_slice($candidates, 1);

            $rules[] = $this->makeRule([
                'concept_id' => $primary['attributes']['concept_id'] ?? null,
                'name' => $primary['attributes']['concept_name'] ?? ($primary['text'] ?? $textKey),
                'description' => $primary['attributes']['description'] ?? '',
                'category' => $primary['attributes']['domain_id'] ?? 'Condition',
                'children' => [],
                'alternatives' => array_map(function ($ent) {
                    return [
                        'concept_id' => $ent['attributes']['concept_id'] ?? null,
                        'name' => $ent['attributes']['concept_name'] ?? ($ent['text'] ?? ''),
                        'description' => $ent['attributes']['description'] ?? '',
                        'category' => $ent['attributes']['domain_id'] ?? 'Condition',
                        'children' => [],
                    ];
                }, $alts),
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
        $segmentCount = count($segments);
        $rules = [];
        foreach ($segments as $i => $segment) {
            \Log::info('Finding OMOP concepts for segment: '.$segment);
            $concepts = $this->getConceptsForSegment($segment);
            \Log::info('Found '.count($concepts));

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
            } elseif (count($concepts) === 1) {
                $rules[] = $concepts[0];
            }

            if ($i < $segmentCount - 1) {
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
