<?php

namespace App\Services\QueryContext\Contexts\Beacon;

use App\Models\Omop\Concept;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextType;
use Illuminate\Support\Facades\Log;
use Throwable;

class BeaconQueryContext implements QueryContextInterface
{
    public function translate(array $definition): array
    {
        // note: this is discovery work
        // - still a lot to be done and improved upon
        // - it shows how simple query can be translated to work with beacon
        try {
            $filters = [];

            foreach ($this->flattenRules($definition) as $rule) {
                if (! isset($rule['field'], $rule['operator'], $rule['value'])) {
                    continue;
                }
                switch ($rule['field']) {
                    case 'age':
                        switch ($rule['operator']) {
                            case 'between':
                                $filters[] = [
                                    'id' => 'ageOfOnset',
                                    'scope' => 'individuals.disease.age.iso8601duration',
                                    'value' => $rule['value'][0],
                                    'operator' => '>',
                                ];
                                $filters[] = [
                                    'id' => 'ageOfOnset',
                                    'scope' => 'individuals.disease.age.iso8601duration',
                                    'value' => $rule['value'][1],
                                    'operator' => '<',
                                ];
                                break;
                            default:
                                $filters[] = [
                                    'id' => 'ageOfOnset',
                                    'scope' => 'individuals.disease.age.iso8601duration',
                                    'value' => $rule['value'],
                                    'operator' => (string) $rule['operator'],
                                ];
                                break;
                        }
                        break;
                    default:
                        $id = $this->mapConceptToCode((int) $rule['value']);
                        if ($id) { // revisit
                            $filters[] = [
                                'id' => $id,
                                'includeDescendantTerms' => true,
                            ];
                            break;
                        }
                }
            }
            $out = [
                'meta' => [
                    'apiVersion' => '2.0',
                ],
                'query' => [
                    'filters' => $filters,
                    'includeResultsetResponses' => 'HIT',
                    'testMode' => false,
                    'requestedGranularity' => 'count',
                ],
            ];

            return $out;
        } catch (Throwable $e) {
            Log::error('Error in QueryTranslator::translate', [
                'message' => $e->getMessage(),
                'definition' => $definition,
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
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

    private function mapConceptToCode(int $conceptId): ?string
    {
        $concept = Concept::where('concept_id', $conceptId)
            ->select(['concept_code', 'vocabulary_id'])
            ->first();

        if (! $concept) {
            // revisit - should warn here?
            return null;
        }

        $vocab = $concept->vocabulary_id;
        $code = $concept->concept_code;

        return "$vocab:$code";
    }

    public function getType(): QueryContextType
    {
        return QueryContextType::Beacon;
    }
}
