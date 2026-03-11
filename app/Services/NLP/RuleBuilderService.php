<?php

namespace App\Services\NLP;

use App\Traits\NLPConceptLookup;
use App\Traits\RuleBuilder;
use App\Services\NLP\Constraints\ConstraintAccumulator;
use Carbon\Carbon;
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

    private bool $hasEntityAgeConstraints = false;
    private bool $hasEntityTimeConstraints = false;

    private function splitTopLevelOr(string $query): array
    {
        // $q = strtolower($query); // LS: Ruins work done with Acronyms. Removing.
        $segments = preg_split('/\s+or\s+/i', $query);

        return array_map('trim', $segments);
    }

    private function getConceptsForSegment(
        string $segment,
        ConstraintAccumulator $constraints,
        array &$warnings
    ): array {
        $this->loadNlpEntities($segment);
        $this->mergeNlpWarnings($warnings);
        $this->applyNlpAgeConstraints($constraints, $warnings);
        $this->applyNlpTimeConstraints($constraints, $warnings);

        $rules = [];

        foreach (($this->nlpGroups ?? []) as $nlpGroup) {
            $groupNode = $this->buildGroupNode($nlpGroup);
            if ($groupNode !== null) {
                $rules[] = $groupNode;
            }
        }

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
                'match_score' => $primary['attributes']['match_score'] ?? 0,
                'tokens' => $primary['attributes']['tokens'] ?? [],
                'phrase_tokens' => $primary['attributes']['phrase_tokens'] ?? [],
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

            $rule = $this->makeRule(
                $concept,
                exclude: (bool)($primary['attributes']['negates'] ?? false)
            );

            $entityTimeConstraints = $this->selectTimeConstraints($primary['time_constraints'] ?? [], 'entity');
            if (! empty($entityTimeConstraints)) {
                $this->hasEntityTimeConstraints = true;
            }

            $entityTime = $this->normalizeTimeConstraints($entityTimeConstraints);
            if ($entityTime !== null) {
                $rule['timeConstraint'] = $entityTime;
            }

            $entityConstraints = $this->selectAgeConstraints($primary['age_constraints'] ?? [], 'entity');
            if (! empty($entityConstraints)) {
                $this->hasEntityAgeConstraints = true;
            }

            $entityAge = $this->normalizeAgeConstraints($entityConstraints);
            if ($entityAge !== null) {
                $hasRange = $entityAge[0] !== null && $entityAge[1] !== null;
                if ($hasRange) {
                    $rules[] = $this->makeGroup([
                        $rule,
                        $this->makeOperator('and'),
                        $this->makeAgeFilterNode($entityAge),
                    ]);
                    continue;
                }

                $rule['ageConstraint'] = $entityAge;
            }

            $rules[] = $rule;
        }

        return $rules;
    }
    /**
     * Parses a query string into a structured rules array.
     */
    public function parseToRules(string $query): array
    {
        $this->hasEntityAgeConstraints = false;
        $this->hasEntityTimeConstraints = false;
        $constraints = new ConstraintAccumulator();
        $segments = $this->splitTopLevelOr($query);

        $segmentCount = count($segments);
        $rules = [];
        $warnings = [];

        $this->applyConstraints($query, $constraints, $warnings);

        foreach ($segments as $i => $segment) {
            \Log::info('Finding OMOP concepts for segment: '.$segment);
            $concepts = $this->getConceptsForSegment($segment, $constraints, $warnings);
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

        $constraintPayload = $constraints->toArray();
        $ageConstraint = $constraintPayload['ageConstraint'] ?? [null, null];
        $timeConstraint = $constraintPayload['timeConstraint'] ?? [null, null];

        if ($this->hasEntityAgeConstraints) {
            $constraintPayload['ageConstraint'] = [null, null];
            $warnings = $this->removeAgeWarnings($warnings);
        } elseif ($ageConstraint !== [null, null]) {
            $ageFilter = $this->makeAgeFilterNode($ageConstraint);
            $constraintPayload['ageConstraint'] = [null, null];

            if (empty($rules)) {
                $rules = [$ageFilter];
            } else {
                if (count($rules) === 1 && isset($rules[0]['rules']) && is_array($rules[0]['rules'])) {
                    $targetRules = &$rules[0]['rules'];
                } else {
                    $rules = [$this->makeGroup($rules)];
                    $targetRules = &$rules[0]['rules'];
                }

                if (! empty($targetRules)) {
                    $targetRules[] = $this->makeOperator('and');
                }
                $targetRules[] = $ageFilter;
            }
        }

        if ($this->hasEntityTimeConstraints) {
            $constraintPayload['timeConstraint'] = [null, null];
            $warnings = $this->removeTimeWarnings($warnings);
        }

        return [
            'id' => Str::uuid()->toString(),
            'rules' => $rules,
            'constraints' => $constraintPayload,
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

            $warnings[] = '"Adults" interpreted as current age >= 18. Please modify from the query builder if needed.';
        }

        if (preg_match('/\bchildren?\b/i', $query)) {
            $constraints->addAgeMax(config('system.default_child_age_max'), true);

            $warnings[] = '"Children" interpreted as current age <= 17. Please modify from the query builder if needed.';
        }

        //////////////////////////////////////////////////////////////////////////////////////////
        // Age constraints
        //////////////////////////////////////////////////////////////////////////////////////////
        if (preg_match('/under (\d+)/i', $query, $m)) {
            $constraints->addAgeMax((int)$m[1], true);

            $warnings[] = "Age under $m[1] interpreted as current age < $m[1]";
        }

        if (preg_match('/over (\d+)/i', $query, $m)) {
            $constraints->addAgeMin((int)$m[1], true);

            $warnings[] = "Age over $m[1] intepreted as current age > $m[1]";
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

    private function applyNlpAgeConstraints(ConstraintAccumulator $constraints, array &$warnings): void
    {
        if ($this->hasEntityAgeConstraints) {
            return;
        }

        $entityScoped = $this->collectEntityAgeConstraints();
        if (! empty($entityScoped)) {
            return;
        }

        $queryConstraints = $this->selectAgeConstraints($this->nlpRootAgeConstraints ?? [], 'query');
        if (empty($queryConstraints)) {
            return;
        }

        $range = $this->normalizeAgeConstraints($queryConstraints);
        if ($range === null) {
            return;
        }

        [$min, $max] = $range;
        $this->applyAgeRangeToAccumulator($constraints, $warnings, $min, $max, 'from NLP (query)');
    }

    private function applyNlpTimeConstraints(ConstraintAccumulator $constraints, array &$warnings): void
    {
        if ($this->hasEntityTimeConstraints) {
            return;
        }

        $entityScoped = $this->collectEntityTimeConstraints();
        if (! empty($entityScoped)) {
            return;
        }

        $queryConstraints = $this->selectTimeConstraints($this->nlpRootTimeConstraints ?? [], 'query');
        if (empty($queryConstraints)) {
            return;
        }

        $range = $this->normalizeTimeConstraints($queryConstraints);
        if ($range === null) {
            return;
        }

        [$from, $to] = $range;
        $this->applyTimeRangeToAccumulator($constraints, $warnings, $from, $to, 'from NLP (query)');
    }

    private function buildGroupNode(array $nlpGroup): ?array
    {
        $entities = $nlpGroup['entities'] ?? [];
        $operator = $nlpGroup['operator'] ?? 'and';

        $groupedByText = collect($entities)
            ->groupBy(fn ($e) => strtolower(trim($e['text'] ?? '')))
            ->map(fn ($group) => $group->values()->all())
            ->toArray();

        $groupRules = [];

        foreach ($groupedByText as $textKey => $candidates) {
            if (empty($candidates)) {
                continue;
            }

            usort(
                $candidates,
                fn ($a, $b) =>
                ($b['attributes']['match_score'] ?? 0) <=> ($a['attributes']['match_score'] ?? 0)
            );

            $primary = $candidates[0];
            $alts = array_slice($candidates, 1);

            $concept = [
                'concept_id' => $primary['attributes']['concept_id'] ?? null,
                'name' => $primary['attributes']['concept_name'] ?? ($primary['text'] ?? $textKey),
                'description' => $primary['attributes']['description'] ?? ($primary['text'] ?? $textKey),
                'category' => $primary['attributes']['domain_id'] ?? 'Condition',
                'children' => [],
                'match_score' => $primary['attributes']['match_score'] ?? 0,
                'tokens' => $primary['attributes']['tokens'] ?? [],
                'phrase_tokens' => $primary['attributes']['phrase_tokens'] ?? [],
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

            if (! empty($groupRules)) {
                $groupRules[] = $this->makeOperator($operator);
            }

            $groupRules[] = $this->makeRule(
                $concept,
                exclude: (bool)($primary['attributes']['negates'] ?? false)
            );
        }

        if (empty($groupRules)) {
            return null;
        }

        return $this->makeGroup($groupRules);
    }

    private function makeAgeFilterNode(array $ageConstraint): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'value' => $ageConstraint,
            'valid' => true,
        ];
    }

    private function mergeNlpWarnings(array &$warnings): void
    {
        foreach (($this->nlpWarnings ?? []) as $warning) {
            if (is_string($warning) && $warning !== '') {
                $warnings[] = $warning;
            }
        }
    }

    private function removeAgeWarnings(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn ($warning) => ! is_string($warning) || ! str_starts_with($warning, 'Age ')
        ));
    }

    private function removeTimeWarnings(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn ($warning) => ! is_string($warning) || ! str_starts_with($warning, 'Recorded ')
        ));
    }

    private function selectAgeConstraints(array $constraints, string $scope): array
    {
        $selected = [];

        foreach ($constraints as $constraint) {
            if (! is_array($constraint)) {
                continue;
            }

            $constraintScope = $constraint['scope'] ?? null;
            if ($constraintScope === null || $constraintScope === $scope) {
                $selected[] = $constraint;
            }
        }

        return $selected;
    }

    private function selectTimeConstraints(array $constraints, string $scope): array
    {
        $selected = [];

        foreach ($constraints as $constraint) {
            if (! is_array($constraint)) {
                continue;
            }

            $constraintScope = $constraint['scope'] ?? null;
            if ($constraintScope === null || $constraintScope === $scope) {
                $selected[] = $constraint;
            }
        }

        return $selected;
    }

    private function normalizeAgeConstraints(array $constraints): ?array
    {
        $min = null;
        $max = null;
        $hasConstraint = false;

        foreach ($constraints as $constraint) {
            if (! is_array($constraint)) {
                continue;
            }

            if (array_key_exists('min', $constraint) || array_key_exists('max', $constraint)) {
                $inclusive = $constraint['inclusive'] ?? true;
                $cMin = is_numeric($constraint['min'] ?? null) ? (int) $constraint['min'] : null;
                $cMax = is_numeric($constraint['max'] ?? null) ? (int) $constraint['max'] : null;

                if ($inclusive === false) {
                    if ($cMin !== null) {
                        $cMin += 1;
                    }
                    if ($cMax !== null) {
                        $cMax -= 1;
                    }
                }

                if ($cMin !== null) {
                    $min = max($min ?? $cMin, $cMin);
                    $hasConstraint = true;
                }
                if ($cMax !== null) {
                    $max = min($max ?? $cMax, $cMax);
                    $hasConstraint = true;
                }
                continue;
            }

            $op = (string)($constraint['operator'] ?? '');
            $values = [];
            foreach (($constraint['values'] ?? []) as $rawValue) {
                if (is_numeric($rawValue)) {
                    $values[] = (int) $rawValue;
                }
            }

            if (in_array($op, ['>', '>='], true) && isset($values[0])) {
                $min = max($min ?? $values[0], $values[0]);
                $hasConstraint = true;
                continue;
            }

            if (in_array($op, ['<', '<='], true) && isset($values[0])) {
                $max = min($max ?? $values[0], $values[0]);
                $hasConstraint = true;
                continue;
            }

            if (in_array($op, ['between', 'range'], true) && count($values) >= 2) {
                $candidateMin = min($values[0], $values[1]);
                $candidateMax = max($values[0], $values[1]);
                $min = max($min ?? $candidateMin, $candidateMin);
                $max = min($max ?? $candidateMax, $candidateMax);
                $hasConstraint = true;
                continue;
            }

            if (in_array($op, ['=', '=='], true) && isset($values[0])) {
                $min = max($min ?? $values[0], $values[0]);
                $max = min($max ?? $values[0], $values[0]);
                $hasConstraint = true;
            }
        }

        if (! $hasConstraint) {
            return null;
        }

        return [$min, $max];
    }

    private function normalizeTimeConstraints(array $constraints): ?array
    {
        $from = null;
        $to = null;
        $hasConstraint = false;

        foreach ($constraints as $constraint) {
            if (! is_array($constraint)) {
                continue;
            }

            $rawFrom = $constraint['from'] ?? null;
            $rawTo = $constraint['to'] ?? null;

            if ($rawFrom !== null) {
                $fromValue = Carbon::parse($rawFrom);
                $from = $from === null ? $fromValue : $from->max($fromValue);
                $hasConstraint = true;
            }

            if ($rawTo !== null) {
                $toValue = Carbon::parse($rawTo);
                $to = $to === null ? $toValue : $to->min($toValue);
                $hasConstraint = true;
            }
        }

        if (! $hasConstraint) {
            return null;
        }

        return [
            $from ? $from->toISOString() : null,
            $to ? $to->toISOString() : null,
        ];
    }

    private function applyAgeRangeToAccumulator(
        ConstraintAccumulator $constraints,
        array &$warnings,
        ?int $min,
        ?int $max,
        string $sourceLabel
    ): void {
        if ($min !== null && $max !== null) {
            $constraints->addAgeMin($min, true);
            $constraints->addAgeMax($max, true);
            return;
        }

        if ($min !== null) {
            $constraints->addAgeMin($min, true);
        }

        if ($max !== null) {
            $constraints->addAgeMax($max, true);
        }
    }

    private function applyTimeRangeToAccumulator(
        ConstraintAccumulator $constraints,
        array &$warnings,
        ?string $from,
        ?string $to,
        string $sourceLabel
    ): void {
        if ($from !== null && $to !== null) {
            $constraints->setTimeRange($from, $to, true);
            return;
        }

        if ($from !== null) {
            $constraints->setTimeRange($from, null, true);
        }

        if ($to !== null) {
            $constraints->setTimeRange(null, $to, true);
        }
    }

    private function collectEntityAgeConstraints(): array
    {
        if (empty($this->nlpEntities)) {
            return [];
        }

        $constraints = [];

        foreach ($this->nlpEntities as $candidates) {
            foreach ($candidates as $entity) {
                $entityConstraints = $this->selectAgeConstraints(
                    $entity['age_constraints'] ?? [],
                    'entity'
                );
                foreach ($entityConstraints as $constraint) {
                    $constraints[] = $constraint;
                }
            }
        }

        return $constraints;
    }

    private function collectEntityTimeConstraints(): array
    {
        if (empty($this->nlpEntities)) {
            return [];
        }

        $constraints = [];

        foreach ($this->nlpEntities as $candidates) {
            foreach ($candidates as $entity) {
                $entityConstraints = $this->selectTimeConstraints(
                    $entity['time_constraints'] ?? [],
                    'entity'
                );
                foreach ($entityConstraints as $constraint) {
                    $constraints[] = $constraint;
                }
            }
        }

        return $constraints;
    }
}
