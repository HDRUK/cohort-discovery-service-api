<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait RuleBuilder
{
    protected function makeGroup(array $rules, bool $exclude = false): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'rules' => $rules,
            'exclude' => $exclude,
        ];
    }

    protected function makeRule(array $concept, bool $exclude = false): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'exclude' => $exclude,
            'rule' => [
                'concept' => $concept,
            ],
        ];
    }

    protected function makeOperator(string $combinator): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'combinator' => $combinator,
            'exclude' => false,
        ];
    }
}
