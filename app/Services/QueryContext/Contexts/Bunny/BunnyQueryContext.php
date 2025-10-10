<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        $groups = [];

        $makeLeafRule = function (array $concept, bool $isExcluded = false): array {
            $rule = [
                'varname' => 'OMOP',
                'varcat'  => $concept['category'] ?? 'UNKNOWN',
                'type'    => 'TEXT',
                'oper'    => $isExcluded ? '!=' : '=',
                'value'   => (string) ($concept['concept_id'] ?? ''),
            ];

            return $rule;
        };

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
            $opBuffer = null;

            foreach ($children as $child) {
                if ($isOperatorNode($child)) {
                    $opBuffer = strtoupper($child['combinator'] ?? 'AND');
                    continue;
                }

                if ($isLeafNode($child)) {
                    $isExcluded = (bool)($child['exclude'] ?? false);

                    $leafRule = $makeLeafRule($child['rule']['concept'], $isExcluded);

                    $seq[] = [
                        'rule'    => $leafRule,
                        'edge_op' => null,
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

                    $groups[] = $g;
                }

                $i = $j + 1;
            }
        };

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
