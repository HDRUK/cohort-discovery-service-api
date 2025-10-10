<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use App\Services\QueryContext\Contexts\Beacon\BeaconQueryContext;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;

class QueryContextTest extends TestCase
{
    private BunnyQueryContext $bunnyContext;
    private BeaconQueryContext $beaconContext;
    private QueryContextManager $manager;

    /*private const INPUT_QUERY = [
        'combinator' => 'and',
        'rules' => [
            [
                'combinator' => 'and',
                'rules' => [
                    [
                        'field' => 'sex',
                        'operator' => '=',
                        'value' => '8532',
                        'id' => '56ecf58e-de34-4fe2-9e3c-b28da2599f33',
                    ],
                    [
                        'field' => 'age',
                        'operator' => '>',
                        'value' => 50,
                        'id' => 'ca612473-e068-45dd-99d0-8f0e3cb35cbf',
                    ],
                    [
                        'field' => 'condition',
                        'operator' => '=',
                        'value' => '4011930',
                        'id' => '791428a0-dfb1-4ce2-8e2c-8c9ca12f3523',
                    ],
                ],
                'id' => '3a97cd6e-31c1-4034-b8ce-a708a42c6746',
            ],
        ],
    ];*/


    private const INPUT_QUERY = [
        "id" => "9f71c79e-8e3c-467c-9970-d8b9ee4badca",
        "rules" => [
            [
                "id" => "91b16f34-c7c8-4a64-b4d9-1c82eb64e353",
                "exclude" => false,
                "rules" => [
                    [
                        "id" => "3f696208-11a8-4daf-86be-ce158b53606c",
                        "exclude" => false,
                        "rule" => [
                            "concept" => [
                                "concept_id" => 3955320,
                                "description" => "Moderna - SARS-CoV-2 (COVID-19) vaccine",
                                "category" => "Drug",
                                "children" => []
                            ]
                        ],
                        "valid" => true
                    ],
                    [
                        "id" => "ca15e2ad-0cca-421e-8012-58cacf0987cd",
                        "combinator" => "or",
                        "exclude" => false,
                        "valid" => true
                    ],
                    [
                        "id" => "08e3d082-f05b-4ab1-9c61-c65a02aac43a",
                        "exclude" => false,
                        "rule" => [
                            "concept" => [
                                "concept_id" => 3955321,
                                "description" => "Pfizer - SARS-CoV-2 (COVID-19) vaccine",
                                "category" => "Drug",
                                "children" => []
                            ]
                        ],
                        "valid" => true
                    ]
                ],
                "valid" => true
            ],
            [
                "id" => "3ceaec2e-3764-4514-ae83-32d0445c37e3",
                "combinator" => "and",
                "exclude" => false,
                "valid" => true
            ],
            [
                "id" => "011bcab3-ec65-42ce-91bf-66e54f4b2a7a",
                "exclude" => true,
                "rule" => [
                    "concept" => [
                        "concept_id" => 3955322,
                        "description" => "Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222",
                        "category" => "Drug",
                        "children" => []
                    ]
                ],
                "valid" => true
            ],
            [
                "id" => "7d79cd1d-43b9-486d-a4a0-d3e4abf2d478",
                "combinator" => "and",
                "exclude" => false,
                "valid" => true
            ],
            [
                "id" => "b4e03e03-8e56-4567-bd61-7b0cada793f4",
                "rule" => [
                    "concept" => [
                        "concept_id" => 3959231,
                        "description" => "Close contact with confirmed COVID-19 case person/patient",
                        "category" => "Observation",
                        "children" => []
                    ]
                ],
                "valid" => true
            ]
        ],
        "valid" => true
    ];



    protected function setUp(): void
    {
        parent::setUp();

        $this->bunnyContext = $this->app->make(BunnyQueryContext::class);
        $this->beaconContext = $this->app->make(BeaconQueryContext::class);
        $this->manager = $this->app->make(QueryContextManager::class);
    }

    public function test_application_has_registered_query_contexts(): void
    {
        $contexts = $this->app->tagged('query_contexts');

        $this->assertNotEmpty($contexts, 'No query contexts registered in the application.');

        foreach ($contexts as $context) {
            $this->assertInstanceOf(
                QueryContextInterface::class,
                $context,
                'Context is not an instance of QueryContextInterface: ' . get_class($context)
            );
        }
    }

    public function test_application_can_translate_bunny_query(): void
    {
        $result = $this->bunnyContext->translate(self::INPUT_QUERY);
        dd($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('groups_oper', $result);

        $firstRule = $result['groups'][0]['rules'][0] ?? null;
        $this->assertIsArray($firstRule);
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        //$this->assertEquals('=', $firstRule['oper'] ?? null);
    }

    public function test_application_can_translate_beacon_query(): void
    {
        $result = $this->beaconContext->translate(self::INPUT_QUERY);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('filters', $result['query']);

        $firstRule = $result['query']['filters'][0] ?? null;
        $this->assertIsArray($firstRule);
        $this->assertEquals('Gender:F', $firstRule['id'] ?? null);
    }

    public function test_application_can_translate_via_manager(): void
    {
        // Bunny query via manager
        $bunnyResult = $this->manager->handle(self::INPUT_QUERY, QueryContextType::Bunny);
        $this->assertIsArray($bunnyResult);
        $this->assertArrayHasKey('groups', $bunnyResult);

        // Beacon query via manager (just echoes JSON back as array)
        $beaconResult = $this->manager->handle(self::INPUT_QUERY, QueryContextType::Beacon);
        $this->assertIsArray($beaconResult);
        $this->assertArrayHasKey('query', $beaconResult);
    }
}
