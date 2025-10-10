<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BunnyQueryContext implements QueryContextInterface
{
    public function translatOld(array $definition): array
    {
        $mapField = function (string $field): array {
            $fieldMap = [
                'sex' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Person',
                    'type' => 'TEXT'
                ],
                'age' => [
                    'varname' => 'AGE',
                    'varcat' => 'Person',
                    'type' => 'NUM'
                ],
                'condition' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Condition',
                    'type' => 'TEXT'
                ],
                'measurement' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Measurement',
                    'type' => 'TEXT'
                ],
                'drug' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Drug',
                    'type' => 'TEXT'
                ],
                'observation' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Observation',
                    'type' => 'TEXT'
                ]
            ];
            return $fieldMap[$field] ?? [
                'varname' => 'UNKNOWN',
                'varcat' => 'UNKNOWN',
                'type' => 'TEXT'
            ];
        };

        $groups = [];


        $processGroup = function (array $node) use (&$groups, &$processGroup, $mapField) {

            $invertOperator = function ($operator, $type) {
                $map = [
                    '='  => '!=',
                    '!=' => '=',
                    '>'  => '<=',
                    '>=' => '<',
                    '<'  => '>=',
                    '<=' => '>',
                    'between' => 'not_between',
                    'not_between' => 'between',
                ];

                return $map[$operator] ?? $operator;
            };

            $group = [
                'rules_oper' => strtoupper($node['combinator'] ?? 'AND'),
                'rules' => [],
            ];

            if (!empty($node['not'])) {
                $group['not'] = true;
            }

            foreach ($node['rules'] as $rule) {
                if (isset($rule['rules'])) {
                    $processGroup($rule);
                } elseif (isset($rule['rule'])) {

                    $op = $rule['combinator'] ?? 'and';
                    $exclude = $rule['exclude'] ?? false;
                    $rule = $rule['rule'];
                    $concept = $rule['concept'];

                    //children

                    $conceptId = $concept['concept_id'];
                    $domain = $concept['category'];

                    $mapped = [
                        'varname' => 'OMOP',
                        'varcat' => $domain,
                        'type' => 'TEXT'
                    ];

                    $type = $mapped['type'];


                    /*if (!empty($node['not'])) {
                        $operator = $invertOperator($operator, $type);
                    }

                    if ($type === 'NUM') {
                        if (!in_array($operator, ['=', '!='])) {
                            switch ($operator) {
                                case '>':
                                case '>=':
                                    $operator = '=';
                                    $value = "{$value}|Inf";
                                    break;

                                case '<':
                                case '<=':
                                    $operator = '=';
                                    $value = "-Inf|{$value}";
                                    break;

                                case 'between':
                                    if (is_array($value) && count($value) === 2) {
                                        [$min, $max] = $value;
                                        $operator = '=';
                                        $value = "{$min}|{$max}";
                                    }
                                    break;

                                case 'not_between':
                                    if (is_array($value) && count($value) === 2) {
                                        [$min, $max] = $value;
                                        $operator = '!=';
                                        $value = "{$min}|{$max}";
                                    }
                                    break;

                                default:
                                    // fallback to >= style
                                    $operator = '=';
                                    $value = "{$value}|Inf";
                                    break;
                            }
                        } elseif (is_numeric($value)) {
                            $value = "{$value}|";
                        } else {
                            $value = (string) $value;
                        }
                    } else {
                        $value = (string) $value;
                    }*/

                    $ruleArray = [
                        'varname' => $mapped['varname'],
                        'varcat'  => $mapped['varcat'],
                        'type'    => $type,
                        //'oper'    => $op,
                        'value'   => str($conceptId),
                    ];


                    //if (isset($rule['time'])) {
                    //    $ruleArray['time'] = $rule['time'];
                    // }

                    $group['rules'][] = $ruleArray;
                } else {
                    dd($rule);
                }
            }

            if (!empty($group['rules'])) {
                $groups[] = $group;
            }
        };

        $processGroup($definition);

        return [
            'groups' => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND')
        ];
    }

    public function translate(array $definition): array
    {
        $groups = [];

        // Build the target "rule" shape from a concept node
        $makeLeafRule = function (array $concept, bool $isExcluded = false): array {
            $rule = [
                'varname' => 'OMOP',
                'varcat'  => $concept['category'] ?? 'UNKNOWN',
                'type'    => 'TEXT',
                'oper'    => $isExcluded ? '!=' : '=',    // NOT => "!=" as per new spec
                'value'   => (string) ($concept['concept_id'] ?? ''),
            ];

            // If your input carries extra fields you want to pass through (e.g., time),
            // you can add them here (or detect them per-node and merge).
            return $rule;
        };

        // Classifiers for the new schema
        $isOperatorNode = function (array $node): bool {
            return isset($node['combinator']) && !isset($node['rule']) && !isset($node['rules']);
        };
        $isLeafNode = function (array $node): bool {
            return isset($node['rule']['concept']) && !isset($node['rules']);
        };
        $isGroupNode = function (array $node): bool {
            return isset($node['rules']);
        };

        /**
         * Process a node:
         *  1) Recurse into nested groups so they emit their own groups.
         *  2) Build a sequential list of leaves and attach a LEFT-edge operator
         *     to each leaf (operator connecting THIS leaf to the NEXT leaf).
         *  3) Chunk by runs of identical LEFT-edge operators and emit groups.
         *     (Excluded leaves stay inline but carry oper "!=".)
         */
        $processNode = function (array $node) use (&$groups, &$processNode, $makeLeafRule, $isOperatorNode, $isLeafNode, $isGroupNode): void {
            $children = $node['rules'] ?? [];
            if (empty($children)) {
                return;
            }

            // 1) Recurse into nested groups first (they stand on their own)
            foreach ($children as $child) {
                if ($isGroupNode($child)) {
                    $processNode($child);
                }
            }

            // 2) Build the leaf sequence and assign LEFT-edge operators
            //    Each item: ['rule'=>array, 'edge_op'=>string|null]
            $seq = [];
            $lastLeafIndex = null;
            $opBuffer = null; // operator to assign to the PREVIOUS leaf

            foreach ($children as $child) {
                if ($isOperatorNode($child)) {
                    $opBuffer = strtoupper($child['combinator'] ?? 'AND');
                    continue;
                }

                if ($isLeafNode($child)) {
                    $isExcluded = (bool)($child['exclude'] ?? false);

                    // Build the rule. If you carry extra per-leaf attributes (e.g., time),
                    // you can merge them in here from $child.
                    $leafRule = $makeLeafRule($child['rule']['concept'], $isExcluded);

                    $seq[] = [
                        'rule'    => $leafRule,
                        'edge_op' => null, // to be assigned to PREVIOUS leaf via buffered operator
                    ];
                    $thisIndex = count($seq) - 1;

                    if ($lastLeafIndex !== null && $opBuffer !== null) {
                        $seq[$lastLeafIndex]['edge_op'] = $opBuffer;
                    }

                    $lastLeafIndex = $thisIndex;
                    $opBuffer = null;
                }
            }

            if (empty($seq)) {
                return;
            }

            // 3) Chunk by runs of identical LEFT-edge operator.
            //    If a leaf lacks edge_op (e.g., last in chain), default to AND.
            $n = count($seq);
            $i = 0;
            while ($i < $n) {
                $op = strtoupper($seq[$i]['edge_op'] ?? 'AND');
                $j  = $i;

                while ($j < $n - 1 && strtoupper($seq[$j]['edge_op'] ?? 'AND') === $op) {
                    $j++;
                }

                // Collect rules i..j inclusive
                $groupRules = [];
                for ($k = $i; $k <= $j; $k++) {
                    $groupRules[] = $seq[$k]['rule'];
                }

                if ($groupRules) {
                    $g = [
                        'rules_oper' => $op,
                        'rules'      => $groupRules,
                    ];
                    // If the current node is negated at the group level, and you still need to support it,
                    // you could either flip each rule's operator or add a 'not' flag here.
                    // For now, we keep rule-level "!=" as the way NOT is represented.
                    $groups[] = $g;
                }

                $i = $j + 1;
            }
        };

        // Kick off from the provided root
        $processNode($definition);

        return [
            'groups'      => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND'),
        ];
    }




    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
