<?php

namespace App\Console\Commands;

use App\Models\Collection;
use App\Services\RegressionTest\RegressionTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedRegressionTests extends Command
{
    protected $signature = 'regression-tests:seed
                            {--collections= : Comma-separated collection PIDs to link each test against}';

    protected $description = 'Seed default global regression tests (user_id = null, admin-owned).';

    public function handle(RegressionTestService $service): int
    {
        $collectionPids = $this->parseCollectionPids();

        if (empty($collectionPids)) {
            $this->error('No collection PIDs provided. Use --collections=pid1,pid2,...');
            return self::FAILURE;
        }

        $collections = Collection::whereIn('pid', $collectionPids)->get()->keyBy(fn ($c) => (string) $c->pid);

        $missing = array_diff($collectionPids, array_keys($collections->toArray()));
        if (!empty($missing)) {
            $this->error('Unknown collection PIDs: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        $collectionInput = $collections->map(fn ($c) => [
            'pid' => (string) $c->pid,
            'expected_result' => null,
        ])->values()->toArray();

        foreach ($this->defaults() as $definition) {
            $test = $service->create([
                'name' => $definition['name'],
                'query_definition' => $definition['query_definition'],
                'collections' => $collectionInput,
            ]);

            $this->info("Created regression test \"{$test->name}\" ({$test->pid})");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function parseCollectionPids(): array
    {
        $raw = $this->option('collections');

        if (!$raw) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $raw)));
    }

    private function defaults(): array
    {
        return [
            [
                'name' => 'All Ages',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        ['id' => (string) Str::uuid(), 'value' => [0, 120], 'valid' => true],
                    ],
                    'valid' => true,
                ],
            ],
            [
                'name' => 'Over 56',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        ['id' => (string) Str::uuid(), 'value' => [56, 120], 'valid' => true],
                    ],
                    'valid' => true,
                ],
            ],
            [
                'name' => 'Male or Female',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        [
                            'id' => (string) Str::uuid(),
                            'exclude' => false,
                            'rule' => ['concept' => ['concept_id' => 8532, 'name' => 'FEMALE', 'category' => 'Gender']],
                            'valid' => true,
                        ],
                        ['id' => (string) Str::uuid(), 'combinator' => 'or', 'valid' => true],
                        [
                            'id' => (string) Str::uuid(),
                            'exclude' => false,
                            'rule' => ['concept' => ['concept_id' => 8507, 'name' => 'MALE', 'category' => 'Gender']],
                            'valid' => true,
                        ],
                    ],
                    'valid' => true,
                ],
            ],
            [
                'name' => 'Males over 50 with asthma or Females over 55 with asthma',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        [
                            'id' => (string) Str::uuid(),
                            'exclude' => false,
                            'rules' => [
                                ['id' => (string) Str::uuid(), 'value' => [50, 120], 'valid' => true],
                                ['id' => (string) Str::uuid(), 'combinator' => 'and', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 8532, 'name' => 'FEMALE', 'category' => 'Gender']],
                                    'valid' => true,
                                ],
                                ['id' => (string) Str::uuid(), 'combinator' => 'and', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 317009, 'name' => 'Asthma', 'category' => 'Condition']],
                                    'valid' => true,
                                ],
                            ],
                            'valid' => true,
                        ],
                        ['id' => (string) Str::uuid(), 'combinator' => 'or', 'valid' => true],
                        [
                            'id' => (string) Str::uuid(),
                            'rules' => [
                                ['id' => (string) Str::uuid(), 'value' => [55, 120], 'valid' => true],
                                ['id' => (string) Str::uuid(), 'combinator' => 'and', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 8507, 'name' => 'MALE', 'category' => 'Gender']],
                                    'valid' => true,
                                ],
                                ['id' => (string) Str::uuid(), 'combinator' => 'and', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 317009, 'name' => 'Asthma', 'category' => 'Condition']],
                                    'valid' => true,
                                ],
                            ],
                            'valid' => true,
                        ],
                    ],
                    'valid' => true,
                ],
            ],
            [
                'name' => 'People with COVID-19 who received the Moderna or Pfizer vaccine',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        [
                            'id' => (string) Str::uuid(),
                            'exclude' => false,
                            'rule' => ['concept' => ['concept_id' => 37311061, 'name' => 'COVID-19', 'category' => 'Condition']],
                            'valid' => true,
                        ],
                        ['id' => (string) Str::uuid(), 'combinator' => 'and', 'exclude' => false, 'valid' => true],
                        [
                            'id' => (string) Str::uuid(),
                            'rules' => [
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 37003518, 'name' => 'SARS-CoV-2 (COVID-19) vaccine, mRNA-1273 0.2 MG/ML Injectable Suspension', 'category' => 'Drug']],
                                    'valid' => true,
                                ],
                                ['id' => (string) Str::uuid(), 'combinator' => 'or', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 37003436, 'name' => 'SARS-CoV-2 (COVID-19) vaccine, mRNA-BNT162b2 0.1 MG/ML Injectable Suspension', 'category' => 'Drug']],
                                    'valid' => true,
                                ],
                            ],
                            'valid' => true,
                        ],
                    ],
                    'valid' => true,
                ],
            ],
            [
                'name' => 'Adults with Type 2 diabetes receiving Insulin',
                'query_definition' => [
                    'id' => (string) Str::uuid(),
                    'rules' => [
                        ['id' => (string) Str::uuid(), 'value' => [18, 120], 'valid' => true],
                        ['id' => (string) Str::uuid(), 'combinator' => 'and', 'valid' => true],
                        [
                            'id' => (string) Str::uuid(),
                            'exclude' => false,
                            'rule' => ['concept' => ['concept_id' => 201826, 'name' => 'Type 2 diabetes mellitus', 'category' => 'Condition']],
                            'valid' => true,
                        ],
                        ['id' => (string) Str::uuid(), 'combinator' => 'and', 'exclude' => false, 'valid' => true],
                        [
                            'id' => (string) Str::uuid(),
                            'rules' => [
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 44191029, 'name' => '3 ML Insulin Glargine 100 UNT/ML Injectable Suspension', 'category' => 'Drug']],
                                    'valid' => true,
                                ],
                                ['id' => (string) Str::uuid(), 'combinator' => 'or', 'valid' => true],
                                [
                                    'id' => (string) Str::uuid(),
                                    'exclude' => false,
                                    'rule' => ['concept' => ['concept_id' => 21136739, 'name' => '3 ML insulin detemir 100 UNT/ML Prefilled Syringe', 'category' => 'Drug']],
                                    'valid' => true,
                                ],
                            ],
                            'valid' => true,
                        ],
                    ],
                    'valid' => true,
                ],
            ],
        ];
    }
}
