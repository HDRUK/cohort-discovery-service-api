<?php

namespace Tests\Unit;

use App\Services\QueryContext\Contexts\Beacon\BeaconQueryContext;
use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;
use Tests\TestCase;

class QueryContextTest extends TestCase
{
    private BunnyQueryContext $bunnyContext;

    private BeaconQueryContext $beaconContext;

    private QueryContextManager $manager;

    private const INPUT_QUERY = [
        'id' => '9f71c79e-8e3c-467c-9970-d8b9ee4badca',
        'rules' => [
            [
                'id' => '91b16f34-c7c8-4a64-b4d9-1c82eb64e353',
                'exclude' => false,
                'rules' => [
                    [
                        'id' => '3f696208-11a8-4daf-86be-ce158b53606c',
                        'exclude' => false,
                        'rule' => [
                            'concept' => [
                                'concept_id' => 3955320,
                                'description' => 'Moderna - SARS-CoV-2 (COVID-19) vaccine',
                                'category' => 'Drug',
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'id' => 'ca15e2ad-0cca-421e-8012-58cacf0987cd',
                        'combinator' => 'or',
                        'exclude' => false,
                        'valid' => true,
                    ],
                    [
                        'id' => '08e3d082-f05b-4ab1-9c61-c65a02aac43a',
                        'exclude' => false,
                        'rule' => [
                            'concept' => [
                                'concept_id' => 3955321,
                                'description' => 'Pfizer - SARS-CoV-2 (COVID-19) vaccine',
                                'category' => 'Drug',
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => '3ceaec2e-3764-4514-ae83-32d0445c37e3',
                'combinator' => 'and',
                'exclude' => false,
            ],
            [
                'id' => '011bcab3-ec65-42ce-91bf-66e54f4b2a7a',
                'exclude' => true,
                'rule' => [
                    'concept' => [
                        'concept_id' => 3955322,
                        'description' => 'Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222',
                        'category' => 'Drug',
                        'children' => [],
                    ],
                ],
            ],
            [
                'id' => '7d79cd1d-43b9-486d-a4a0-d3e4abf2d478',
                'combinator' => 'and',
                'exclude' => false,
            ],
            [
                'id' => 'b4e03e03-8e56-4567-bd61-7b0cada793f4',
                'rule' => [
                    'concept' => [
                        'concept_id' => 3959231,
                        'description' => 'Close contact with confirmed COVID-19 case person/patient',
                        'category' => 'Observation',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    private const ALT_INPUT_QUERY = [
        'id' => 'ef9af804-78b8-46d8-91a8-42d8236ef6bf',
        'rules' => [
            [
                'id' => '962b041d-8957-4b4a-b1bf-4a74bc712c51',
                'exclude' => false,
                'rule' => [
                    'concept' => [
                        'concept_id' => 3955322,
                        'description' => 'Oxford, AstraZeneca - SARS-CoV-2 (COVID-19) vaccine AZD1222',
                        'category' => 'Drug',
                        'children' => [],
                    ],
                ],
                'valid' => true,
            ],
            [
                'id' => 'e5b283cd-8681-49c7-8046-664d937bc83a',
                'combinator' => 'and',
                'valid' => true,
            ],
            [
                'id' => '04a0a135-aa35-44ba-a148-bedee094c4d2',
                'rule' => [
                    'concept' => [
                        'name' => '3955321',
                        'concept_id' => 3955321,
                        'description' => 'Pfizer - SARS-CoV-2 (COVID-19) vaccine',
                        'category' => 'Drug',
                        'children' => [],
                    ],
                ],
                'valid' => true,
            ],
            [
                'id' => '00ff5058-3d91-40b5-901c-09822334ebcb',
                'combinator' => 'and',
                'valid' => true,
            ],
            [
                'id' => '8aeaca43-e5c8-4ea6-b234-d3ba6b02b523',
                'exclude' => false,
                'rule' => [
                    'concept' => [
                        'name' => '3955320',
                        'concept_id' => 3955320,
                        'description' => 'Moderna - SARS-CoV-2 (COVID-19) vaccine',
                        'category' => 'Drug',
                        'children' => [],
                    ],
                ],
                'valid' => true,
            ],
        ],
        'valid' => true,
    ];

    private const ALT_INPUT_QUERY_2 = [
        "id" => "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
        "rules" => [
            [
                "id" => "e80e5a6b-71c6-46a0-8c5e-0545efd0d7fc",
                "rules" => [
                    [
                        "id" => "6247a863-e423-4046-941c-c13c808ddd34",
                        "rule" => [
                            "concept" => [
                                "name" => "Asthma",
                                "count" => "102710",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 317009,
                                "match_score" => 500,
                                "ncollections" => 2
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ],
                    [
                        "id" => "75f1ea35-87b0-44e6-958e-081e70c7c3a5",
                        "valid" => true,
                        "combinator" => "and"
                    ],
                    [
                        "id" => "10981e93-1005-4985-bf5c-474aef1702ce",
                        "rule" => [
                            "concept" => [
                                "name" => "Chronic renal failure",
                                "count" => "93150",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 198185,
                                "match_score" => 500,
                                "ncollections" => 3
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ]
                ],
                "valid" => true,
                "exclude" => false
            ],
            [
                "id" => "4268ee9c-6b8f-49b9-8661-2f5a884b1c3d",
                "valid" => true,
                "combinator" => "and"
            ],
            [
                "id" => "5c998880-129d-4914-9d51-138beebc0fbe",
                "rules" => [
                    [
                        "id" => "c007a95a-fa3f-4440-8e4c-6cdcf8ea4412",
                        "rule" => [
                            "concept" => [
                                "name" => "FEMALE",
                                "count" => "2059520",
                                "category" => "Gender",
                                "children" => [],
                                "concept_id" => 8532,
                                "match_score" => 1000,
                                "ncollections" => 4
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ],
                    [
                        "id" => "b31dddd8-ea97-4be8-a39d-531899dd444b",
                        "valid" => true,
                        "combinator" => "and"
                    ],
                    [
                        "id" => "3101fa62-41b5-4af2-a467-d4777391c318",
                        "rule" => [
                            "concept" => [
                                "name" => "Long Covid-19",
                                "count" => "140610",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 3959287,
                                "match_score" => 500,
                                "ncollections" => 1
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ]
                ],
                "valid" => true,
                "exclude" => false
            ]
        ],
        "valid" => true
    ];

    private const ALT_INPUT_QUERY_3 = [
        "id" => "78f29361-03f8-45b6-ba2c-1ab4e950e6cf",
        "rules" => [
            [
                "id" => "26489189-5dc3-46ef-bb78-de64d8f67e47",
                "rules" => [
                    [
                        "id" => "d84155f2-3955-463c-aa12-02a434691360",
                        "rules" => [
                            [
                                "id" => "925d07bc-3635-44ac-8599-d6cc450207f4",
                                "rule" => [
                                    "concept" => [
                                        "name" => "Heart disease",
                                        "count" => "51570",
                                        "category" => "Condition",
                                        "children" => [],
                                        "concept_id" => 321588,
                                        "match_score" => 500,
                                        "ncollections" => 2
                                    ],
                                ],
                                "valid" => true,
                                "exclude" => false
                            ],
                            [
                                "id" => "3b37f627-5bd0-49cb-bb94-782dcaac4b52",
                                "valid" => true,
                                "combinator" => "and"
                            ],
                            [
                                "id" => "61b6e0af-b31e-45de-967e-f476cdd6e116",
                                "rule" => [
                                    "concept" => [
                                        "name" => "Congestive heart failure",
                                        "count" => "80070",
                                        "category" => "Condition",
                                        "children" => [],
                                        "concept_id" => 319835,
                                        "match_score" => 500,
                                        "ncollections" => 2
                                    ]
                                ],
                                "valid" => true,
                                "exclude" => false
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ],
                    [
                        "id" => "5a3e0b77-46a6-450a-b624-c9cd85cb2716",
                        "valid" => true,
                        "combinator" => "and"
                    ],
                    [
                        "id" => "f5a0a20c-fb7d-48e7-8c04-d4bb168727ed",
                        "rules" => [
                            [
                                "id" => "5eaee5ee-8120-4f6f-a693-babdccb30bcb",
                                "rule" => [
                                    "concept" => [
                                        "name" => "Metastatic non-small cell lung cancer",
                                        "count" => "3380",
                                        "category" => "Condition",
                                        "children" => [],
                                        "concept_id" => 36684857,
                                        "match_score" => 500,
                                        "ncollections" => 2
                                    ]
                                ],
                                "valid" => true,
                                "exclude" => false
                            ],
                            [
                                "id" => "320b0599-80b2-4cfd-91a0-5a37d7576c31",
                                "valid" => true,
                                "combinator" => "and"
                            ],
                            [
                                "id" => "135b51ff-561d-47f4-9968-097a1a4c02c3",
                                "rule" => [
                                    "concept" => [
                                        "name" => "Chronic obstructive pulmonary disease",
                                        "count" => "231310",
                                        "category" => "Condition",
                                        "children" => [],
                                        "concept_id" => 255573,
                                        "match_score" => 500,
                                        "ncollections" => 2
                                    ]
                                ],
                                "valid" => true,
                                "exclude" => false
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ]
                ],
                "valid" => true,
                "exclude" => false
            ],
            [
                "id" => "8ddb891d-f8fc-4b2e-8cc1-62966df86829",
                "valid" => true,
                "combinator" => "and"
            ],
            [
                "id" => "93a79ee4-e21f-476e-ab9a-f0dd7de7e566",
                "rules" => [
                    [
                        "id" => "a34871a9-5bda-4f7c-b746-4d6841a43969",
                        "rule" => [
                            "concept" => [
                                "name" => "Chronic kidney disease stage 3",
                                "count" => "45570",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 443597,
                                "match_score" => 500,
                                "ncollections" => 3
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ],
                    [
                        "id" => "d9c22922-b95c-4ea5-a4ab-099bb31fafef",
                        "valid" => true,
                        "combinator" => "and"
                    ],
                    [
                        "id" => "7ec4e7b3-c754-47ec-8dfe-0a7991f5fbe4",
                        "rule" => [
                            "concept" => [
                                "name" => "Gastrointestinal hemorrhage",
                                "count" => "58830",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 192671,
                                "match_score" => 500,
                                "ncollections" => 2
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ]
                ],
                "valid" => true,
                "exclude" => false
            ],
            [
                "id" => "d32efffd-7c7f-41ac-9395-06ca9ef91a8c",
                "valid" => true,
                "combinator" => "and"
            ],
            [
                "id" => "2acaa3aa-5395-4836-a956-d17395296a8f",
                "rules" => [
                    [
                        "id" => "30b5c86f-5385-4b65-abe7-2bb6583f9828",
                        "rule" => [
                            "concept" => [
                                "name" => "FEMALE",
                                "count" => "2059520",
                                "category" => "Gender",
                                "children" => [],
                                "concept_id" => 8532,
                                "match_score" => 1000,
                                "ncollections" => 4
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ],
                    [
                        "id" => "1204bbc6-9990-4526-a324-a1c0c998aa22",
                        "valid" => true,
                        "combinator" => "or"
                    ],
                    [
                        "id" => "c3cbab6d-105d-47d2-b29a-c6f7a48ccd19",
                        "rule" => [
                            "concept" => [
                                "name" => "Long Covid-19",
                                "count" => "140610",
                                "category" => "Condition",
                                "children" => [],
                                "concept_id" => 3959287,
                                "match_score" => 500,
                                "ncollections" => 1
                            ]
                        ],
                        "valid" => true,
                        "exclude" => false
                    ]
                ],
                "valid" => true,
                "exclude" => false
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
                'Context is not an instance of QueryContextInterface: '.get_class($context)
            );
        }
    }

    public function test_application_can_translate_bunny_query(): void
    {
        // AND( OR(Moderna, Pfizer), AstraZeneca-excluded, CloseContact ) maps
        // directly onto "groups_oper: AND" with one OR-group and one AND-group -
        // no Cartesian distribution needed since neither group nests a group.
        $result = $this->bunnyContext->translate(self::INPUT_QUERY);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertEquals('AND', $result['groups_oper']);
        $this->assertCount(2, $result['groups']);

        $firstGroup = $result['groups'][0];
        $this->assertIsArray($firstGroup['rules']);
        $this->assertCount(2, $firstGroup['rules']);
        $this->assertEquals('OR', $firstGroup['rules_oper'] ?? null);

        $firstRule = $firstGroup['rules'][0] ?? null;
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        $this->assertEquals('3955320', $firstRule['value'] ?? null);
        $secondRule = $firstGroup['rules'][1] ?? null;
        $this->assertEquals('3955321', $secondRule['value'] ?? null);

        $secondGroup = $result['groups'][1];
        $this->assertEquals('AND', $secondGroup['rules_oper'] ?? null);
        $this->assertCount(2, $secondGroup['rules']);
        $this->assertEquals('3955322', $secondGroup['rules'][0]['value'] ?? null);
        $this->assertEquals('3959231', $secondGroup['rules'][1]['value'] ?? null);
    }

    public function test_application_can_translate_bunny_query_alt(): void
    {
        $result = $this->bunnyContext->translate(self::ALT_INPUT_QUERY);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('groups_oper', $result);
        $this->assertEquals('OR', $result['groups_oper']);
        $this->assertCount(1, $result['groups']);

        $firstGroup = $result['groups'][0];
        $this->assertIsArray($firstGroup['rules']);
        $this->assertCount(3, $firstGroup['rules']);
        $this->assertEquals('AND', $firstGroup['rules_oper'] ?? null);

        $firstRule = $firstGroup['rules'][0] ?? null;
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        $this->assertEquals('3955322', $firstRule['value'] ?? null);
        $secondRule = $firstGroup['rules'][1] ?? null;
        $this->assertEquals('3955321', $secondRule['value'] ?? null);
        $thirdRule = $firstGroup['rules'][2] ?? null;
        $this->assertEquals('3955320', $thirdRule['value'] ?? null);
    }

    public function test_application_can_translate_bunny_query_alt_2(): void
    {
        $result = $this->bunnyContext->translate(self::ALT_INPUT_QUERY_2);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('groups_oper', $result);
        $this->assertEquals('OR', $result['groups_oper']);
        $this->assertCount(1, $result['groups']);

        $firstGroup = $result['groups'][0];
        $this->assertIsArray($firstGroup['rules']);
        $this->assertCount(4, $firstGroup['rules']);
        $this->assertEquals('AND', $firstGroup['rules_oper'] ?? null);

        $fourthRule = $firstGroup['rules'][3] ?? null;
        $this->assertEquals('OMOP', $fourthRule['varname'] ?? null);
        $this->assertEquals('3959287', $fourthRule['value'] ?? null);
    }

    public function test_application_can_translate_bunny_query_alt_3(): void
    {
        $result = $this->bunnyContext->translate(self::ALT_INPUT_QUERY_3);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('groups_oper', $result);
        $this->assertEquals('OR', $result['groups_oper']);
        $this->assertCount(2, $result['groups']);

        $firstGroup = $result['groups'][0];
        $this->assertIsArray($firstGroup['rules']);
        $this->assertCount(7, $firstGroup['rules']);
        $this->assertEquals('AND', $firstGroup['rules_oper'] ?? null);

        $secondGroup = $result['groups'][1];
        $this->assertIsArray($secondGroup['rules']);
        $this->assertCount(7, $secondGroup['rules']);
        $this->assertEquals('AND', $secondGroup['rules_oper'] ?? null);

        $firstRule = $firstGroup['rules'][0] ?? null;
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        $this->assertEquals('321588', $firstRule['value'] ?? null);

        $fourthRule = $firstGroup['rules'][3] ?? null;
        $this->assertEquals('OMOP', $fourthRule['varname'] ?? null);
        $this->assertEquals('255573', $fourthRule['value'] ?? null);

        $seventhRule = $firstGroup['rules'][6] ?? null;
        $this->assertEquals('OMOP', $seventhRule['varname'] ?? null);
        $this->assertEquals('8532', $seventhRule['value'] ?? null);

        $firstRule = $secondGroup['rules'][0] ?? null;
        $this->assertEquals('OMOP', $firstRule['varname'] ?? null);
        $this->assertEquals('321588', $firstRule['value'] ?? null);

        $fourthRule = $secondGroup['rules'][3] ?? null;
        $this->assertEquals('OMOP', $fourthRule['varname'] ?? null);
        $this->assertEquals('255573', $fourthRule['value'] ?? null);

        $seventhRule = $secondGroup['rules'][6] ?? null;
        $this->assertEquals('OMOP', $seventhRule['varname'] ?? null);
        $this->assertEquals('3959287', $seventhRule['value'] ?? null);
    }

    public function test_application_can_translate_bunny_gender_as_person(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 8507,
                            'name' => 'MALE',
                            'category' => 'Gender',
                            'children' => [],
                        ],
                    ],
                    'valid' => true,
                    'exclude' => false,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);

        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('Person', $rule['varcat'] ?? null);
        $this->assertEquals('8507', $rule['value'] ?? null);
    }

    public function test_application_can_translate_bunny_race_as_person(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 8527,
                            'name' => 'White',
                            'category' => 'Race',
                            'children' => [],
                        ],
                    ],
                    'valid' => true,
                    'exclude' => false,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);

        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('Person', $rule['varcat'] ?? null);
        $this->assertEquals('8527', $rule['value'] ?? null);
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

    public function test_multi_concept_rule_propagates_age_constraint_to_all_expanded_rules(): void
    {
        $input = ['id' => 'root', 'rules' => [
            [
                'id'            => 'r1',
                'exclude'       => false,
                'rule'          => ['concept' => [
                    ['concept_id' => 605554,   'category' => 'Condition'],
                    ['concept_id' => 3959296,  'category' => 'Observation'],
                    ['concept_id' => 37311061, 'category' => 'Condition'],
                ]],
                'valid'         => true,
                'ageConstraint' => [10, null],
            ],
        ]];

        $result = $this->bunnyContext->translate($input);

        $this->assertEquals('OR', $result['groups_oper']);
        $this->assertCount(3, $result['groups']);

        foreach ($result['groups'] as $group) {
            $rule = $group['rules'][0];
            $this->assertArrayHasKey('time', $rule, 'Each expanded concept rule must carry the age constraint');
            $this->assertEquals('10|:AGE:Y', $rule['time']);
        }
    }

    public function test_multi_concept_rule_expands_to_or_groups(): void
    {
        $input = ['id' => 'root', 'rules' => [
            ['id' => 'r1', 'exclude' => false, 'rule' => ['concept' => [
                ['concept_id' => 37311061, 'category' => 'Condition'],
                ['concept_id' => 605554,   'category' => 'Condition'],
                ['concept_id' => 37311060, 'category' => 'Observation'],
            ]], 'valid' => true],
        ]];

        $result = $this->bunnyContext->translate($input);

        $this->assertEquals('OR', $result['groups_oper']);
        $this->assertCount(3, $result['groups']);
        $this->assertEquals('37311061', $result['groups'][0]['rules'][0]['value']);
        $this->assertEquals('Condition', $result['groups'][0]['rules'][0]['varcat']);
        $this->assertEquals('605554', $result['groups'][1]['rules'][0]['value']);
        $this->assertEquals('37311060', $result['groups'][2]['rules'][0]['value']);
        $this->assertEquals('Observation', $result['groups'][2]['rules'][0]['varcat']);
    }

    public function test_multi_concept_rule_anded_with_single_concept_avoids_distribution(): void
    {
        $input = [
            'id'    => 'root',
            'rules' => [
                [
                    'id'      => 'r1',
                    'exclude' => false,
                    'rule'    => [
                        'concept' => [
                            ['concept_id' => 37311061, 'category' => 'Condition'],
                            ['concept_id' => 605554,   'category' => 'Condition'],
                        ],
                    ],
                    'valid'   => true,
                ],
                ['id' => 'op', 'combinator' => 'and'],
                [
                    'id'      => 'r2',
                    'exclude' => false,
                    'rule'    => [
                        'concept' => ['concept_id' => 3955322, 'category' => 'Drug'],
                    ],
                    'valid'   => true,
                ],
            ],
        ];

        $result = $this->bunnyContext->translate($input);

        // (C1 OR C2) AND D maps directly onto "groups_oper: AND" with an
        // OR-group for the concept alternatives and an AND-group for D - no
        // need to distribute D across each alternative.
        $this->assertEquals('AND', $result['groups_oper']);
        $this->assertCount(2, $result['groups']);
        $this->assertEquals('OR', $result['groups'][0]['rules_oper'] ?? null);
        $this->assertCount(2, $result['groups'][0]['rules']); // C1, C2
        $this->assertEquals('37311061', $result['groups'][0]['rules'][0]['value']);
        $this->assertEquals('605554', $result['groups'][0]['rules'][1]['value']);
        $this->assertEquals('AND', $result['groups'][1]['rules_oper'] ?? null);
        $this->assertCount(1, $result['groups'][1]['rules']); // D
        $this->assertEquals('3955322', $result['groups'][1]['rules'][0]['value']);
    }

    public function test_measurement_concept_with_value_range_encodes_as_num_rule(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 46236952,
                            'description' => 'Body weight',
                            'category' => 'Measurement',
                            'children' => [],
                        ],
                    ],
                    'valueAsNumber' => [1.0, 3.0],
                    'exclude' => false,
                    'valid' => true,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);
        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('OMOP=46236952', $rule['varname']);
        $this->assertEquals('Measurement', $rule['varcat']);
        $this->assertEquals('NUM', $rule['type']);
        $this->assertEquals('=', $rule['oper']);
        $this->assertEquals('1|3', $rule['value']);
    }

    public function test_measurement_concept_with_only_lower_bound(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 46236952,
                            'category' => 'Measurement',
                            'children' => [],
                        ],
                    ],
                    'valueAsNumber' => [5.5, null],
                    'exclude' => false,
                    'valid' => true,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);
        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('OMOP=46236952', $rule['varname']);
        $this->assertEquals('NUM', $rule['type']);
        $this->assertEquals('5.5|1000000000', $rule['value']);
    }

    public function test_measurement_concept_with_only_upper_bound(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 46236952,
                            'category' => 'Measurement',
                            'children' => [],
                        ],
                    ],
                    'valueAsNumber' => [null, 10.0],
                    'exclude' => false,
                    'valid' => true,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);
        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('OMOP=46236952', $rule['varname']);
        $this->assertEquals('NUM', $rule['type']);
        $this->assertEquals('-1000000000|10', $rule['value']);
    }

    public function test_measurement_concept_without_value_range_encodes_as_text_rule(): void
    {
        $input = [
            'rules' => [
                [
                    'rule' => [
                        'concept' => [
                            'concept_id' => 46236952,
                            'category' => 'Measurement',
                            'children' => [],
                        ],
                    ],
                    'exclude' => false,
                    'valid' => true,
                ],
            ],
            'valid' => true,
        ];

        $result = $this->bunnyContext->translate($input);
        $rule = $result['groups'][0]['rules'][0];

        $this->assertEquals('OMOP', $rule['varname']);
        $this->assertEquals('TEXT', $rule['type']);
        $this->assertEquals('46236952', $rule['value']);
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
