<?php

namespace App\Services\NLP;

use App\Traits\NLPConceptLookup;
use App\Traits\RuleBuilder;
use App\Services\NLP\Constraints\ConstraintAccumulator;
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

            usort(
                $candidates,
                fn ($a, $b) =>
                ($b['attributes']['match_score'] ?? 0) <=> ($a['attributes']['match_score'] ?? 0)
            );

            $primary = $candidates[0];
            $children = [];
            $alts = array_slice($candidates, 1);

            $concept = [
                'concept_id' => $primary['attributes']['concept_id'] ?? null,
                'name' => $primary['attributes']['concept_name'] ?? ($primary['text'] ?? $textKey),
                'description' => $primary['attributes']['description'] ?? ($primary['text'] ?? $textKey),
                'category' => $primary['attributes']['domain_id'] ?? 'Condition',
                'children' => $children,
                'alternatives' => array_map(function ($ent) {
                    return [
                        'concept_id' => $ent['attributes']['concept_id'] ?? null,
                        'name' => $ent['attributes']['concept_name'] ?? ($ent['text'] ?? ''),
                        'description' => $ent['attributes']['description'] ?? '',
                        'category' => $ent['attributes']['domain_id'] ?? 'Condition',
                        'children' => [],
                    ];
                }, $alts),
            ];

            $rules[] = $this->makeRule(
                $concept,
                exclude: (bool)($primary['attributes']['negates'] ?? false)
            );
        }

        return $rules;
    }
    /**
     * Parses a query string into a structured rules array.
     */
    public function parseToRules(string $query): array
    {
        $constraints = new ConstraintAccumulator();
        $segments = $this->splitTopLevelOr($query);

        $segmentCount = count($segments);
        $rules = [];
        $warnings = [];

        $this->applyConstraints($query, $constraints, $warnings);

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
            'constraints' => $constraints->toArray(),
            'warnings' => array_values(array_unique($warnings)),
            'valid' => true,
        ];
    }

    private function applyConstraints(string $query, ConstraintAccumulator $constraints, array &$warnings): void
    {
        //////////////////////////////////////////////////////////////////////////////////////////
        // Ambiguous persons
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/\badults?\b/i', $query)) {
            $constraints->addAgeMin(config('system.default_adult_age_min'), true);

            $warnings[] = 'Ambiguous meaning of "adults"';
        }

        if (preg_match('/\bchildren?\b/i', $query)) {
            $constraints->addAgeMax(config('system.default_child_age_max'), true);

            $warnings[] = 'Ambiguous meaning of "children"';
        }

        //////////////////////////////////////////////////////////////////////////////////////////
        // Age constraints
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/under (\d+)/i', $query, $m)) {
            $constraints->addAgeMax((int)$m[1], true);

            $warnings[] = 'Age < ' . $m[1] . ' (ambiguous: at diagnosis vs current)';
        }

        if (preg_match('/over (\d+)/i', $query, $m)) {
            $constraints->addAgeMin((int)$m[1], true);

            $warnings[] = 'Age > ' . $m[1] . ' (ambiguous: at diagnosis vs current)';
        }

        if (preg_match('/aged (\d+)[–-](\d+)/i', $query, $m)) {
            $constraints->addAgeMin((int)$m[1], true);
            $constraints->addAgeMax((int)$m[2], true);

            $warnings[] = 'Age between ' . $m[1] . ' and ' . $m[2] . ' (ambiguous: at diagnosis vs current)';
        }

        //////////////////////////////////////////////////////////////////////////////////////////
        // Time constraints
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/last (\d+) (year|years|month|months)/i', $query, $m)) {
            $to = now()->toISOString();
            $from = now()->sub($m[2] === 'year' ? 'years' : 'months', (int)$m[1])->toISOString();
            $constraints->setTimeRange($from, $to, true);

            $warnings[] = 'Recorded within last ' . $m[1] . ' ' . $m[2];
        }

        //////////////////////////////////////////////////////////////////////////////////////////
        // Unsupported features
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/visit|gp|hospital/i', $query)) {
            $warnings[] = 'Visit-based filtering is not supported yet';
        }

        //////////////////////////////////////////////////////////////////////////////////////////
        // Geographic constraints
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/region|regions|locale|area|areas|england|scotland|wales|northern ireland/i', $query)) {
            $warnings[] = 'Geographic filtering is not supported yet';
        }
    }
}
