<?php

namespace App\Services\QueryContext\Contexts\Beacon;

use App\Models\Omop\Concept;
use App\Services\QueryContext\QueryContextType;
use App\Services\QueryContext\Contexts\QueryContextInterface;

class BeaconQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        $filters = [];

        foreach ($this->flattenRules($definition) as $rule) {
            if (!isset($rule['field'], $rule['operator'], $rule['value'])) {
                continue;
            }
            switch ($rule['field']) {
                case 'age':
                    $filters[] = [
                        'id' => 'ageOfOnset',
                        'value' => (string)$rule['value'],
                        'operator' => (string)$rule['operator'],
                    ];
                    break;

                default:
                    $id = $this->mapConceptToCode((string)$rule['value']);
                    $filters[] = [
                        'id' => $id,
                        'includeDescendantTerms' => true,
                    ];
                    break;
            }
        }
        $out = [
            'meta' => [
                'apiVersion' => '2.0',
            ],
            'query' => [
                'filters' => array_values($filters),
                'includeResultsetResponses' => 'HIT',
                'testMode' => false,
                'requestedGranularity' => 'count',
            ],
        ];

        return $out;
    }

    private function flattenRules(array $node): array
    {
        $leaves = [];

        if (isset($node['rules']) && is_array($node['rules'])) {
            foreach ($node['rules'] as $child) {
                $leaves = array_merge($leaves, $this->flattenRules($child));
            }
        } else {
            $leaves[] = $node;
        }

        return $leaves;
    }

    private function mapConceptToCode(string $conceptId): ?string
    {
        $concept = Concept::where('concept_id', $conceptId)
            ->select(['concept_code', 'vocabulary_id'])
            ->first();
        $vocab = $concept->vocabulary_id;
        $code = $concept->concept_code;

        return "$vocab:$code";
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Beacon;
    }
}
