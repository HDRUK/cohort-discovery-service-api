<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition, bool $flattenNestedGroups = true): array
    {
        // Convert to groupwise form for easier parsing of nodes per group.
        $groupwiseForm = $this->convertToGroupwiseForm($definition);

        // Check for the special case where it's only a single group of ANDs -
        // in this case we can skip the flattening step and just convert to "standard form" (OR-of-ANDs) directly
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

        if ($specialForm) {
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

        if (!$flattenNestedGroups) {
            // Equally, if we want to skip the flattening step, then we can just return as-is with modified outer layer.
            return [
                "groups_oper" => $groupwiseForm['rules_oper'] ?? 'AND',
                "groups" => $groupwiseForm['rules'] ?? [],
            ];
        }

        // Now we know it's not in that form, collapse to "standard form".
        return $this->flattenToStandardForm($groupwiseForm, 0);
    }

    private function convertGroup(array $node, string $groupOperator): array
    {
        $groups = [];
        $children = $node['rules'] ?? [];
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                $groups[] = $this->convertToGroupwiseForm($child);
            } elseif ($this->isLeafNode($child)) {
                $groups[] = $this->makeLeafRule($child);
            } elseif ($this->isAgeFilter($child)) {
                $groups[] = $this->makeLeafAgeFilter($child);
            }
        }

        return [
            'rules_oper' => $groupOperator,
            'rules' => $groups,
        ];
    }

    /**
     * Converts a rule definition into a "groupwise form", where each node is either:
     * - a leaf node (a single rule)
     * - a group node (a group of rules with a combinator)
     * The groupwise form is easier to parse for the next step of flattening into "standard form".
     *
     * Example input:
     * [
     *   'id' => '9f71c79e-8e3c-467c-9970-d8b9ee4badca',
     *   'rules' => [
     *       [
     *           'id' => '91b16f34-c7c8-4a64-b4d9-1c82eb64e353',
     *           'exclude' => false,
     *           'rules' => [
     *               [
     *                   'id' => '3f696208-11a8-4daf-86be-ce158b53606c',
     *                   'exclude' => false,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955320,
     *                           'description' => 'Moderna - SARS-CoV-2 (COVID-19) vaccine',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *               [
     *                   'id' => 'ca15e2ad-0cca-421e-8012-58cacf0987cd',
     *                   'combinator' => 'or',
     *                   'exclude' => false,
     *                   'valid' => true,
     *               ],
     *               [
     *                   'id' => '08e3d082-f05b-4ab1-9c61-c65a02aac43a',
     *                   'exclude' => false,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955321,
     *                           'description' => 'Pfizer - SARS-CoV-2 (COVID-19) vaccine',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *           ],
     *       ],
     *       [
     *           'id' => '3ceaec2e-3764-4514-ae83-32d0445c37e3',
     *           'combinator' => 'and',
     *           'exclude' => false,
     *       ],
     *       [
     *           'id' => '011bcab3-ec65-42ce-91bf-66e54f4b2a7a',
     *           'exclude' => true,
     *           'rule' => [
     *               'concept' => [
     *                   'concept_id' => 3955322,
     *                   'description' => 'Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222',
     *                   'category' => 'Drug',
     *                   'children' => [],
     *               ],
     *           ],
     *       ],
     *   ],
     * ]
     *
     * Example output:
     * [
     *   "rules_oper" => "OR",
     *   "rules" => [
     *       [
     *           "rules_oper" => "AND",
     *           "rules" => [
     *               [
     *                   'id' => '3f696208-11a8-4daf-86be-ce158b53606c',
     *                   'exclude' => false,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955320,
     *                           'description' => 'Moderna - SARS-CoV-2 (COVID-19) vaccine',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *               [
     *                   'id' => '011bcab3-ec65-42ce-91bf-66e54f4b2a7a',
     *                   'exclude' => true,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955322,
     *                           'description' => 'Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *           ],
     *       ],
     *       [
     *           "rules_oper" => "AND",
     *           "rules" => [
     *               [
     *                   'id' => '08e3d082-f05b-4ab1-9c61-c65a02aac43a',
     *                   'exclude' => false,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955321,
     *                           'description' => 'Pfizer - SARS-CoV-2 (COVID-19) vaccine',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *               [
     *                   'id' => '011bcab3-ec65-42ce-91bf-66e54f4b2a7a',
     *                   'exclude' => true,
     *                   'rule' => [
     *                       'concept' => [
     *                           'concept_id' => 3955322,
     *                           'description' => 'Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222',
     *                           'category' => 'Drug',
     *                           'children' => [],
     *                       ],
     *                   ],
     *               ],
     *           ],
     *       ],
     *    ],
     * ]
    **/
    private function convertToGroupwiseForm(array $node): array
    {
        $groupOperator = $this->groupOperator($node);
        if ($groupOperator || $this->isGroupNode($node)) {
            return $this->convertGroup($node, $groupOperator ?? 'OR');
        } else {
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

    private function combineStandardsWithAnd(array $first, array $second): array
    {
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
     * Flattens a groupwise form into "standard form" with a maximum depth of 2 groups
     * (i.e. OR of ANDs), by recursively applying the following rules:,
     * 1) ((A or B) and (C or D)) → ((A and C) or (B and C) or (A and D) or (B and D))
     * 2) (A and (B and C)) → (A and B and C)
     * 3) (A or (B or C)) → (A or B or C)
     *
     * @param array $groupwiseForm The groupwise form to process.
     * @return array The transformed structure in "standard form".
     *
     * This function always returns in the form of
     * [
     *   "rules_oper": "OR",
     *   "rules": [
     *     [
     *      "rules_oper": "AND",
     *     "rules": [ ... ] // leaf rules
     *     ],
     *     ... // more AND groups
     *   ]
     * ]
     * except when it's at the top level, where it returns
     * [
     *   "groups_oper": "OR",
     *   "groups": [
     *     ...
     *   ]
     * ]
     * as the outer layer
     */
    private function flattenToStandardForm(array $groupwiseForm, int $depth): array
    {
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
            } else {
                // Child has children. Check that _its_ children are all in standard form,
                // then convert this to standard form recursively
                $standardisedChildren[] = $this->flattenToStandardForm($rule, $depth + 1);
            }
        }

        // All $standarisedChildren are of the form "standard form"
        // specifically
        // [
        //    [ "rules_oper" => "OR", "rules" => [["rules_oper" => "AND", "rules" => [$rule]], [ ...] ], ]
        //    ...
        // ]
        //
        // Loop back again over all the standardised children.
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
        } else {
            // $groupOperator is AND, we need to distribute
            if (count($standardisedChildren) < 2) {
                return $standardisedChildren[0] ?? [
                    "rules_oper" => "OR",
                    "rules" => []
                ];
            }
            $standardisedRules = $this->combineStandardsWithAnd($standardisedChildren[0], $standardisedChildren[1]);
            foreach ($standardisedChildren as $index => $standardisedChild) {
                if ($index === 0 || $index === 1) {
                    continue; // already combined the first two children
                }
                $standardisedRules = $this->combineStandardsWithAnd($standardisedRules, $standardisedChild);
            }
            $standardisedForm = $standardisedRules;
        }

        // We now have a single object of "standard form" (OR-of-ANDs)
        // If we're at the top level, rename to "groups" for the final form
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

        if (array_is_list($concept)) {
            return [
                'rules_oper' => 'OR',
                'rules'      => array_map(
                    fn (array $c) => $this->makeSingleConceptRule($child, $c),
                    $concept
                ),
            ];
        }

        return $this->makeSingleConceptRule($child, $concept);
    }

    private function makeSingleConceptRule(array $child, array $concept): array
    {
        $isExcluded = (bool) ($child['exclude'] ?? false);
        $timeConstraint = $child['timeConstraint'] ?? [null, null];
        $ageConstraint = $child['ageConstraint'] ?? [null, null];

        $category = $concept['category'] ?? 'UNKNOWN';

        if (in_array($category, ['Gender', 'Ethnicity', 'Race'], true)) {
            $category = 'Person';
        }

        $rule = [
            'varname' => 'OMOP',
            'varcat'  => $category,
            'type'    => 'TEXT',
            'oper'    => $isExcluded ? '!=' : '=',
            'value'   => (string) ($concept['concept_id'] ?? ''),
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
        $ageLeaf = [
            'varname' => 'AGE',
            'varcat'  => 'Person',
            'type'    => 'NUM',
            'oper'    => '=',
            'value'   => $child['age'][0].'|'.$child['age'][1],
        ];

        $parts = [$ageLeaf];

        if (! empty($child['sex'])) {
            $parts[] = $this->makePersonConceptFilter($child['sex']);
        }

        if (! empty($child['race'])) {
            $parts[] = $this->makePersonConceptFilter($child['race']);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return [
            'rules_oper' => 'AND',
            'rules'      => $parts,
        ];
    }

    private function makePersonConceptFilter(array $concepts): array
    {
        if (count($concepts) === 1) {
            return $this->makePersonConceptLeaf($concepts[0]);
        }

        return [
            'rules_oper' => 'OR',
            'rules'      => array_map(fn ($c) => $this->makePersonConceptLeaf($c), $concepts),
        ];
    }

    private function makePersonConceptLeaf(array $concept): array
    {
        return [
            'varname' => 'OMOP',
            'varcat'  => 'Person',
            'type'    => 'TEXT',
            'oper'    => '=',
            'value'   => (string) $concept['concept_id'],
        ];
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
        return isset($node['age']) && ! isset($node['rules']) && ! isset($node['rule']);
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
