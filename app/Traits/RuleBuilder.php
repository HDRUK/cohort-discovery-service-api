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

    public function normalise_characters(string $s): string
    {
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KC);
        }

        $map = [
            // Dashes / minus / hyphens
            "\u{2010}" => '-', // Hyphen
            "\u{2011}" => '-', // Non-breaking hyphen
            "\u{2012}" => '-', // Figure dash
            "\u{2013}" => '-', // En dash
            "\u{2014}" => '-', // Em dash
            "\u{2212}" => '-', // Minus sign

            // Spaces for sanity sake
            "\u{00A0}" => ' ', // Non-breaking space
            "\u{202F}" => ' ', // Narrow no-break space
            "\u{200B}" => ' ', // Zero width space
        ];

        return strtr($s, $map);
    }
}
