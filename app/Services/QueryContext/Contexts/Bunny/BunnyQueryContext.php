<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Carbon\Carbon;

class BunnyQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        $groups = [];

        $this->processNode($definition, $groups);

        return [
            'groups' => $groups,
            'groups_oper' => strtoupper($definition['combinator'] ?? 'AND'),
        ];
    }

    /*
    - Note: this entire piece will need to be revisited
    - - functions getting more complex - needs to be
    */
    protected function processNode(array $node, array &$groups): void
    {
        $children = $node['rules'] ?? [];
        if (empty($children)) {
            return;
        }

        // 1) recurse into nested groups first
        foreach ($children as $child) {
            if ($this->isGroupNode($child)) {
                $this->processNode($child, $groups);
            }
        }

        // 2) flatten this level into leaf list + operator list
        //    $leafRules[i]   = rule for leaf i
        //    $ops[i]         = operator betwene leaf (i-1) and leaf i
        $leafRules = [];
        $ops = [];
        $pendingOp = null;

        foreach ($children as $child) {
            if ($this->isOperatorNode($child)) {
                $pendingOp = strtoupper($child['combinator'] ?? 'AND');
                continue;
            }
            $leafRule = null;
            if ($this->isLeafNode($child)) {
                $leafRule = $this->makeLeafRule($child);
            } elseif ($this->isAgeFilter($child)) {
                $leafRule = $this->makeLeafAgeFilter($child);
            } elseif ($this->isGroupNode($child)) {
                //throw new \Error('No support for groups within groups yet');
                continue;
            } else {
                throw new \Error('unknown leaf rule' . json_encode($child));
            }
            $leafRules[] = $leafRule;
            $leafIndex = count($leafRules) - 1;

            // operator applies between previous leaf and this one
            if ($pendingOp !== null && $leafIndex > 0) {
                $ops[$leafIndex] = $pendingOp;
            }

            $pendingOp = null;

        }

        $n = count($leafRules);
        if ($n === 0) {
            return;
        }

        // Only one leaf at this level → single AND-group
        if ($n === 1) {
            $groups[] = [
                'rules_oper' => 'AND',
                'rules' => [$leafRules[0]],
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
        $currentOp = null;

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
                        'rules' => $currentBlock,
                    ];

                    $currentBlock = [$lastRule, $leafRules[$i]];
                } else {
                    // only one leaf in the old block
                    $groups[] = [
                        'rules_oper' => $currentOp,
                        'rules' => $currentBlock,
                    ];

                    $currentBlock = [$leafRules[$i]];
                }
                /** @phpstan-ignore-next-line */
                $currentOp = $op ?? 'AND';
            }
        }
        $groups[] = [
            'rules_oper' => $currentOp ?? 'AND', // @phpstan-ignore-line
            'rules' => $currentBlock,
        ];
    }

    protected function makeLeafRule(array $child): array
    {
        $concept = $child['rule']['concept'];
        $isExcluded = (bool) ($child['exclude'] ?? false);
        $timeConstraint = $child['timeConstraint'] ?? [null, null];
        $ageConstraint = $child['ageConstraint'] ?? [null, null];

        $category = $concept['category'] ?? 'UNKNOWN';

        if ($category === 'Gender' || $category === 'Ethnicity') {
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
