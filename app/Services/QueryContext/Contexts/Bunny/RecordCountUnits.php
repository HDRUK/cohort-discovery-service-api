<?php

namespace App\Services\QueryContext\Contexts\Bunny;

use Laravel\Pennant\Feature;

class RecordCountUnits
{
    public const FEATURE = 'query-record-counting';

    private const COUNTABLE_VARCAT = 'Specimen';

    public function forCohort(array $cohort): array
    {
        if (! Feature::active(self::FEATURE)) {
            return [];
        }

        $hasCountableInclusion = collect($this->leafRules($cohort['groups'] ?? []))
            ->contains(fn (array $rule) => ($rule['varcat'] ?? null) === self::COUNTABLE_VARCAT
                && ($rule['oper'] ?? null) === '=');

        return $hasCountableInclusion ? [self::COUNTABLE_VARCAT] : [];
    }

    private function leafRules(array $groups): array
    {
        $leaves = [];

        foreach ($groups as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                $leaves = array_merge(
                    $leaves,
                    isset($rule['rules']) ? $this->leafRules([$rule]) : [$rule],
                );
            }
        }

        return $leaves;
    }
}
