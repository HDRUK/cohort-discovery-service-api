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
                    ]
                ],
            ],
            [
                "id" => "3ceaec2e-3764-4514-ae83-32d0445c37e3",
                "combinator" => "and",
                "exclude" => false,
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
            ],
            [
                "id" => "7d79cd1d-43b9-486d-a4a0-d3e4abf2d478",
                "combinator" => "and",
                "exclude" => false,
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
            ]
        ],
    ];*/


    private const INPUT_QUERY = [
        "id" => "ef9af804-78b8-46d8-91a8-42d8236ef6bf",
        "rules" => [
            [
                "id" => "962b041d-8957-4b4a-b1bf-4a74bc712c51",
                "exclude" => false,
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
                "id" => "e5b283cd-8681-49c7-8046-664d937bc83a",
                "combinator" => "and",
                "valid" => true
            ],
            [
                "id" => "04a0a135-aa35-44ba-a148-bedee094c4d2",
                "rule" => [
                    "concept" => [
                        "name" => "3955321",
                        "concept_id" => 3955321,
                        "description" => "Pfizer - SARS-CoV-2 (COVID-19) vaccine",
                        "category" => "Drug",
                        "children" => []
                    ]
                ],
                "valid" => true
            ],
            [
                "id" => "00ff5058-3d91-40b5-901c-09822334ebcb",
                "combinator" => "or",
                "valid" => true
            ],
            [
                "id" => "8aeaca43-e5c8-4ea6-b234-d3ba6b02b523",
                "exclude" => false,
                "rule" => [
                    "concept" => [
                        "name" => "3955320",
                        "concept_id" => 3955320,
                        "description" => "Moderna - SARS-CoV-2 (COVID-19) vaccine",
                        "category" => "Drug",
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
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('groups_oper', $result);

        $firstGroup = $result['groups'][0];
        $this->assertIsArray($firstGroup['rules']);
        $this->assertCount(2, $firstGroup['rules']);
        $this->assertEquals('OR', $firstGroup['rules_oper'] ?? null);

        $firstRule = $firstGroup['rules'][0] ?? null;
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        $this->assertEquals('3955320', $firstRule['value'] ?? null);
        $secondRule = $firstGroup['rules'][1] ?? null;
        $this->assertEquals('3955321', $secondRule['value'] ?? null);
    }

    /*
    note: disabling for now
          in the future we'll support beacon context translation of our JSON
          but not now, it's too much work to implement when we wont be using beacon for MVP1
    public function test_application_can_translate_beacon_query(): void
    {
        $result = $this->beaconContext->translate(self::INPUT_QUERY);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('query', $result);
        $this->assertArrayHasKey('filters', $result['query']);

        $firstRule = $result['query']['filters'][0] ?? null;
        $this->assertIsArray($firstRule);
        $this->assertEquals('Gender:F', $firstRule['id'] ?? null);
    }*/

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
