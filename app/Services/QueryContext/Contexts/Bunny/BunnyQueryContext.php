<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;


class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        // Convert to groupwise form for easier parsing of nodes per group.
        $groupwiseForm = $this->convertToGroupwiseForm($definition);

        // Check for the special case where it's only a single group of ANDs - in this case we can skip the flattening step and just convert to final form directly
        $specialForm = true;
        if ($groupwiseForm['rules_oper'] === 'OR') {
            $specialForm = false;
        }
        
        foreach ($groupwiseForm['rules'] as $child) {
            if ($this->isGroupNode($child)) {
                $specialForm = false;
                break;
            }
        }
        
        if ($specialForm)   {
            $rules = $groupwiseForm["rules"];
            return [
                "groups_oper" => 'OR',
                "groups" => [
                        [
                            "rules_oper" => 'AND',
                            "rules" => $rules
                        ]
                    ]
                ];
        }

        // Now we know it's not that special form, it is guaranteed to collapse to an OR of ANDs.
        return $this->flattenToMaxDepthTwo($groupwiseForm, 0);
    }

    private function convertGroup(array $node, string $groupOperator): array
    {
        $groups = [];
        $children = $node['rules'] ?? [];
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                $groups[] = $this->convertToGroupwiseForm($child);
            }
            elseif ($this->isLeafNode($child)) {
                $groups[] = $this->makeLeafRule($child);
            }
            elseif ($this->isAgeFilter($child)) {
                $groups[] = $this->makeLeafAgeFilter($child);
            }
        }

        return [
            'rules_oper' => $groupOperator,
            'rules' => $groups,
        ];
    }

    private function convertToGroupwiseForm(array $node): array
    {
        $groupOperator = $this->groupOperator($node);
        if ($groupOperator || $this->isGroupNode($node)) {
            return $this->convertGroup($node, $groupOperator ?? 'OR');
        }
        else {
            $leafRule = null;
            if ($this->isLeafNode($node)) {
                $leafRule = $this->makeLeafRule($node);
            } elseif ($this->isAgeFilter($node)) {
                $leafRule = $this->makeLeafAgeFilter($node);
            } else {
                throw new \Error('unknown leaf rule' . json_encode($node));
            }
            return $leafRule;
        }
    }

    private function groupOperator(array $node): ?string
    {
        return $this->isGroupNode($node) && count($node['rules']) > 1 && isset($node['rules'][1]['combinator']) ? strtoupper($node['rules'][1]['combinator']) : null;
    }

    private function hasOperator(array $rule): bool
    {
        return array_key_exists("rules_oper", $rule);
    }

    private function combineStandardsWithAnd(array $first, array $second): array {
        // Given two arrays of standardised rules of the form 
        // $first = ["rules_oper" => "OR", 
        //           "rules" => [ 
        //              ["rules_oper" => "AND", "rules" => [A, B]], 
        //              ["rules_oper" => "AND", "rules" => [C]], 
        //                       ]
        //           ], 
        // $second = ["rules_oper" => "OR", 
        //           "rules" => [ 
        //              ["rules_oper" => "AND", "rules" => [G, H]], 
        //              ["rules_oper" => "AND", "rules" => [I, J]], 
        //              ["rules_oper" => "AND", "rules" => [K]]
        //                       ]
        //           ],
        // combine them via the AND operator into a single standardised rule of the form 
        // ["rules_oper" => "OR", 
        //           "rules" => [ 
        //              ["rules_oper" => "AND", "rules" => [A, B, G, H]], 
        //              ["rules_oper" => "AND", "rules" => [A, B, I, J]], 
        //              ["rules_oper" => "AND", "rules" => [A, B, K]], 
        //              ["rules_oper" => "AND", "rules" => [C, G, H]], 
        //              ["rules_oper" => "AND", "rules" => [C, I, J]], 
        //              ["rules_oper" => "AND", "rules" => [C, K]], 
        //                       ]
        //           ]

        $combinedRules = [];
        foreach ($first['rules'] as $firstRule) {
            // $firstRule is of the form ["rules_oper" => "AND", "rules" => [A, B]] 
            $innerNewStandardisedRules = [];
            foreach ($second["rules"] as $secondRule) {
                // $secondRule is of the form ["rules_oper" => "AND", "rules" => [G, H]] 
                $combinedRules[] = [
                    "rules_oper" => "AND", 
                    "rules" => array_merge(
                        $firstRule["rules"], 
                        $secondRule["rules"]
                    )
                ];
            }
        }

        return [
            "rules_oper" => "OR", 
            "rules" => $combinedRules
        ];
    }

    /**
     * Flattens a groupwise form into a maximum depth of 2 groups,
     * applying the following rules:
     * 1) ((A or B) and (C or D)) → ((A and C) or (B and C) or (A and D) or (B and D))
     * 2) (A and (B and C)) → (A and B and C)
     * 3) (A or (B or C)) → (A or B or C)
     *
     * @param array $groupwiseForm The groupwise form to process.
     * @return array The transformed structure with a maximum depth of 2 groups.
     */
    private function flattenToMaxDepthTwo(array $groupwiseForm, int $depth): array
    {
        // This function always returns in the form of 
        // [
        //   "rules_oper": "OR",
        //   "rules": [nesteds]]
        //
        // so that we are always confident in what we're parsing.
        
        // If the incoming rules are singular, we can convert them into OR of AND anyway
        
        $groupOperator = $groupwiseForm['rules_oper'] ?? 'AND';
        $rules = $groupwiseForm['rules'] ?? [];

        $standardisedChildren = [];
        // Loop over all children, converting each to standard form
        foreach ($rules as $rule) {
            if (!$this->hasOperator($rule)) {
                // Child is a singleton. It won't have its own rules. Return OR([AND([$rule])])
                $standardisedChildren[] = [
                    "rules_oper" => "OR",
                    "rules" => [
                        [
                            "rules_oper" => "AND",
                            "rules" => [
                                $rule
                            ]
                        ]
                    ]
                ];
            }
            else {
                // Child has children. Check that _its_ children are all in standard form, 
                // then convert this to standard form recursively
                $standardisedChildren[] = $this->flattenToMaxDepthTwo($rule, $depth+1);
            }
        }

        // $standarisedChildren is of the form [ OR([AND([$rule])]), OR([AND([$rule])]), ... ];
        // specifically 
        // [ 
        //    [ "rules_oper" => "OR", "rules" => [["rules_oper" => "AND", "rules" => [$rule]] ], 
        //    ...
        // ]
        // Then, loop back again over all the standardised children. These will all be of the form OR of AND
        // Combine these using the current group operator:
        // - If it's an OR, then we spread all children into one array
        // - If it's an AND, then we distribute
        
        if ($groupOperator === "OR") {
            $standardisedRules = [];
            foreach ($standardisedChildren as $standardisedChild) {
                $standardisedRules = array_merge($standardisedRules, $standardisedChild['rules']);
            }

            $standardisedForm = [
                "rules_oper" => "OR",
                "rules" => $standardisedRules
            ];
        }
        else {
            // $groupOperator is AND, we need to distribute
            if (count($standardisedChildren) < 2) {
                return $standardisedChildren[0] ?? [
                    "rules_oper" => "OR",
                    "rules" => []
                ];
            }
            $standardisedRules = $this->combineStandardsWithAnd($standardisedChildren[0], $standardisedChildren[1]);
            foreach($standardisedChildren as $index => $standardisedChild) {
                if ($index === 0 || $index === 1) {
                    continue; // already combined the first two children
                }
                $standardisedRules = $this->combineStandardsWithAnd($standardisedRules, $standardisedChild);
            }
            $standardisedForm = $standardisedRules;
        }

        // We now have a single thing of form OR-of-ANDs
        // Finally, if we're at the top level, rename to "groups" for the final form
        if ($depth === 0) {
            return [
                "groups_oper" => "OR",
                "groups" => $standardisedForm['rules']
            ];
        }

        return $standardisedForm;
    }

    protected function makeLeafRule(array $child): array
    {
        $concept = $child['rule']['concept'];
        $isExcluded = (bool) ($child['exclude'] ?? false);
        $timeConstraint = $child['timeConstraint'] ?? [null, null];
        $ageConstraint = $child['ageConstraint'] ?? [null, null];

        $category = $concept['category'] ?? 'UNKNOWN';

        if (in_array($category, ['Gender', 'Ethnicity', 'Race'], true)) {
            $category = 'Person';
        }

        $rule = [
            'varname' => 'OMOP',
            'varcat' => $category,
            'type' => 'TEXT',
            'oper' => $isExcluded ? '!=' : '=',
            'value' => (string) ($concept['concept_id'] ?? ''),
        ];

        // note: bunny cannot handle both time and age constraints
        // - try time constraint then fallback to age constraint
        $bunnyTime = null;
        if (count($timeConstraint) === 2) {
            [$lower, $upper] = $timeConstraint;
            $bunnyTime = $this->encodeBunnyTimeConstraint($lower, $upper);
        }

        if ($bunnyTime === null && count($ageConstraint) === 2) {
            [$lower, $upper] = $ageConstraint;
            $bunnyTime = $this->encodeBunnyAgeConstraint($lower, $upper);
        }

        if ($bunnyTime !== null) {
            $rule['time'] = $bunnyTime;
        }

        return $rule;
    }

    protected function makeLeafAgeFilter(array $child): array
    {
        $values = $child['value'];
        $rule = [
            'varname' => 'AGE',
            'varcat' => 'Person',
            'type' => 'NUM',
            'oper' => '=',
            'value' => $values[0].'|'.$values[1],
        ];
        return $rule;
    }

    protected function isOperatorNode(array $node): bool
    {
        return isset($node['combinator']) && ! isset($node['rule']) && ! isset($node['rules']);
    }

    protected function isLeafNode(array $node): bool
    {
        return isset($node['rule']['concept']) && ! isset($node['rules']);
    }

    protected function isGroupNode(array $node): bool
    {
        return isset($node['rules']);
    }

    protected function isAgeFilter(array $node): bool
    {
        return isset($node['value']) && ! isset($node['rules'])  && ! isset($node['rule']);
    }

    public function getRelativeMonths(string $date): int
    {
        $now = Carbon::today();
        $other = Carbon::parse($date);

        return (int) round(abs($now->diffInMonths($other, false)));
    }

    public function encodeBunnyAgeConstraint(?int $lower, ?int $upper): ?string
    {
        if (is_null($lower) && is_null($upper)) {
            return null;
        }
        return $lower !== null ? $lower.'|:AGE:Y' : '|'.$upper.':AGE:Y';
    }

    public function encodeBunnyTimeConstraint(
        ?string $lower,
        ?string $upper,
    ): ?string {

        if (is_null($lower) && is_null($upper)) {
            return null;
        }

        // !! BUNNY warning
        // - we are only able to encode left or right operator
        // - not an 'inbetween' and you'd think would be logical
        // - we have to default to use lower for now

        [$date, $pattern] = $lower !== null
            ? [
                $lower,
                '%d|:TIME:M'
            ]
            : [
                $upper,
                '|%d:TIME:M'
            ];

        $months = $this->getRelativeMonths($date);

        return sprintf($pattern, $months);
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
