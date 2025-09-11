<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
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
                } elseif (isset($rule['field'], $rule['operator'], $rule['value'])) {
                    $mapped = $mapField($rule['field']);
                    $type = $mapped['type'];

                    $operator = $rule['operator'];
                    $value = $rule['value'];


                    if (!empty($node['not'])) {
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
                    }

                    $time = $rule['time'];

                    $group['rules'][] = [
                        'varname' => $mapped['varname'],
                        'varcat' => $mapped['varcat'],
                        'type'    => $type,
                        'oper'    => $operator,
                        'value'   => $value,
                        'time'    => $time,
                    ];
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

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
