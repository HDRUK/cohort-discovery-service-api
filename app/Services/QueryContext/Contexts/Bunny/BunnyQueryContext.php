<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use Carbon\Carbon;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        $groups = [];

        $makeLeafRule = function (array $concept, bool $isExcluded = false, array $timeConstraint = []): array {
            $rule = [
                'varname' => 'OMOP',
                'varcat'  => $concept['category'] ?? 'UNKNOWN',
                'type'    => 'TEXT',
                'oper'    => $isExcluded ? '!=' : '=',
                'value'   => (string) ($concept['concept_id'] ?? ''),
            ];

            if (count($timeConstraint) === 2) {
                [$upper, $lower] = $timeConstraint;

                $bunnyTime = $this->encodeBunnyTimeConstraint($lower, $upper);
                if (!is_null($bunnyTime)) {
                    $rule['time'] = $bunnyTime;
                }
            }

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

        /*
        - Note: this entire piece will need to be revisited 
        */
        $processNode = function (array $node) use (&$groups, &$processNode, $makeLeafRule, $isOperatorNode, $isLeafNode, $isGroupNode): void {
            $children = $node['rules'] ?? [];
            if (empty($children)) {
                return;
            }

            // 1) recurse into nested groups first
            foreach ($children as $child) {
                if ($isGroupNode($child)) {
                    $processNode($child);
                }
            }

            // 2) flatten this level into leaf list + operator list
            //    $leafRules[i]   = rule for leaf i
            //    $ops[i]         = operator betwene leaf (i-1) and leaf i
            $leafRules = [];
            $ops       = [];
            $pendingOp = null;

            foreach ($children as $child) {
                if ($isOperatorNode($child)) {
                    $pendingOp = strtoupper($child['combinator'] ?? 'AND');
                    continue;
                }

                if ($isLeafNode($child)) {
                    $isExcluded     = (bool)($child['exclude'] ?? false);
                    $timeConstraint = $child['timeConstraint'] ?? [null, null];
                    $leafRule       = $makeLeafRule($child['rule']['concept'], $isExcluded, $timeConstraint);

                    $leafRules[] = $leafRule;
                    $leafIndex   = count($leafRules) - 1;

                    // operator applies between previous leaf and this one
                    if ($pendingOp !== null && $leafIndex > 0) {
                        $ops[$leafIndex] = $pendingOp;
                    }

                    $pendingOp = null;
                }
            }

            $n = count($leafRules);
            if ($n === 0) {
                return;
            }

            // Only one leaf at this level → single AND-group
            if ($n === 1) {
                $groups[] = [
                    'rules_oper' => 'AND',
                    'rules'      => [$leafRules[0]],
                ];
                return;
            }

            // 3) group leaves:
            //    - ops[i] is the operator between leaf i-1 and i
            //    - when operator changes, we takethe last leaf into
            //      the new block so that e.g. A AND B AND C OR D =>
            //      [A AND B] + [C OR D]
            // - this needs to be revisited 
            $currentBlock = [$leafRules[0]];
            $currentOp    = null;

            for ($i = 1; $i < $n; $i++) {
                $op = $ops[$i] ?? null;
                if ($currentOp === null) {
                    $currentOp = $op ?? 'AND';
                }

                if ($op === $currentOp || $op === null) {
                    $currentBlock[] = $leafRules[$i];
                } else {
                    if (count($currentBlock) >= 2) {
                        $lastRule = array_pop($currentBlock);

                        $groups[] = [
                            'rules_oper' => $currentOp,
                            'rules'      => $currentBlock,
                        ];

                        $currentBlock = [$lastRule, $leafRules[$i]];
                    } else {
                        // only one leaf in the old block
                        $groups[] = [
                            'rules_oper' => $currentOp,
                            'rules'      => $currentBlock,
                        ];

                        $currentBlock = [$leafRules[$i]];
                    }
                    /** @phpstan-ignore-next-line */
                    $currentOp = $op ?? 'AND';
                }
            }
            $groups[] = [
                'rules_oper' => $currentOp ?? 'AND', // @phpstan-ignore-line
                'rules'      => $currentBlock,
            ];
        };

        $processNode($definition);

        return [
            'groups'      => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND'),
        ];
    }

    public function getRelativeMonths(string $date): int
    {
        $now   = Carbon::today();
        $other = Carbon::parse($date);
        return (int)round(abs($now->diffInMonths($other, false)));
    }


    public function encodeBunnyTimeConstraint(?string $lower, ?string $upper): ?string
    {
        if (is_null($lower) && is_null($upper)) {
            return null;
        }

        // !! BUNNY warning
        // - we are only able to encode left or right operator
        // - not an 'inbetween' and you'd think would be logical
        // - we have to default to use lower for now

        [$date, $pattern] = !is_null($lower) ? [$lower, '%d|:TIME:M'] : [$upper, '|%d:TIME:M'];
        $months = $this->getRelativeMonths($date);
        return sprintf($pattern, $months);
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Bunny;
    }
}
