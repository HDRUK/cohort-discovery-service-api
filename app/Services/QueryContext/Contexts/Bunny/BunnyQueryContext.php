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
                    'varname' => 'OMOP',
                    'varcat' => 'Age',
                    'type' => 'NUMBER'
                ],
                'condition' => [
                    'varname' => 'OMOP',
                    'varcat' => 'Condition',
                    'type' => 'TEXT'
                ],
            ];
            return $fieldMap[$field] ?? [
                'varname' => 'UNKNOWN',
                'varcat' => 'UNKNOWN',
                'type' => 'TEXT'
            ];
        };

        $groups = [];


        $processGroup = function (array $node) use (&$groups, &$processGroup, $mapField) {
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
                    $group['rules'][] = [
                        'varname' => $mapped['varname'],
                        'varcat' => $mapped['varcat'],
                        'type'    => $mapped['type'],
                        'oper'    => $rule['operator'],
                        'value'   => $rule['value'],
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
