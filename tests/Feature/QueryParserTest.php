<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class QueryParserTest extends TestCase
{
    private const BASE_URL = '/api/v1/parse-query';

    private array $queries = [
        [
            'query' => 'People who (received Moderna COVID-19 vaccine or Pfizer COVID-19 vaccine) and not (received Oxford, AstraZeneca COVID-19 vaccine) and observed with Close contact with confirmed COVID-19 case person and measured with SARS-CoV-2 antibody to nucleocapsid (N) protein present and diagnosed with Chronic kidney disease stage 3',
            'file' => 'test_query_1.json',
        ],
        [
            'query' => 'People who tested Positive for Influenza A virus and not (tested Positive for SARS-CoV-2) and measured with Leukocyte count decreased and diagnosed with Pneumonia',
            'file' => 'test_query_2.json',
        ],
        [
            'query' => 'Individuals who (received either Moderna or Pfizer COVID-19 vaccines), excluding Oxford-AstraZeneca recipients, observed to have close exposure to a confirmed COVID-19 case, measured positive for SARS-CoV-2 nucleocapsid antibodies, and diagnosed with chronic kidney disease stage 3',
            'file' => 'test_query_3.json',
        ],
        [
            'query' => 'Individuals who (received Moderna or Pfizer COVID-19 shots), without having received Oxford-AstraZeneca, had close contact with a confirmed COVID-19 patient, were found positive for SARS-CoV-2 N protein antibodies, and carry a diagnosis of chronic kidney disease stage 3',
            'file' => 'test_query_4.json',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_can_parse_queries()
    {
        foreach ($this->queries as $q) {
            $response = $this->postJson(self::BASE_URL, [
                'query' => $q['query'],
            ]);
            $response->assertStatus(200);
            $content = $this->stripDynamicIds(json_decode($response->json('data'), true));

            $this->assertNotNull($content);

            $expectedQuery = json_decode(file_get_contents(__DIR__ . '/files/' . $q['file']), true);
            $this->assertEquals(
                $expectedQuery,
                $content
            );
        }
    }

    protected function stripDynamicIds(array $data): array
    {
        $data = Arr::except($data, ['id']);

        if (isset($data['rules'])) {
            $data['rules'] = array_map(fn ($r) => $this->stripDynamicIds($r), $data['rules']);
        }

        if (isset($data['rule']['concept']['children'])) {
            $data['rule']['concept']['children'] = array_map(fn ($r) => $this->stripDynamicIds($r), $data['rule']['concept']['children']);
        }

        return $data;
    }

}
