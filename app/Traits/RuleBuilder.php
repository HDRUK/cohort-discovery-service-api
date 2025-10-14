<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait RuleBuilder
{
    protected function makeGroup(array $rules, bool $exclude = false): array
    {
        return [
            'id' => Str::uuid()->toString(), // for testing right now
            'rules' => $rules,
            'exclude' => $exclude,
        ];
    }

    protected function makeRule(array $concept, bool $exclude = false): array
    {
        return [
            'id' => Str::uuid()->toString(), // for testing right now
            'exclude' => $exclude,
            'rule' => [
                'concept' => $concept,
            ],
        ];
    }

    protected function makeOperator(string $combinator): array
    {
        return [
            'id' => Str::uuid()->toString(), // for testing right now
            'combinator' => $combinator,
            'exclude' => false,
        ];
    }
}
