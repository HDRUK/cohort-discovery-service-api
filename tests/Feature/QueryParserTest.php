<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class QueryParserTest extends TestCase
{
    private const BASE_URL = '/api/v1/parse-query';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_can_parse_a_query_1()
    {
        $payload = [
            'query' => '
                People who received Moderna COVID-19 vaccine or received Pfizer COVID-19 vaccine and not (received Oxford, AstraZeneca COVID-19 vaccine) and observed with Close contact with confirmed COVID-19 case person and measured with SARS-CoV-2 antibody to nucleocapsid (N) protein present and diagnosed with Chronic kidney disease stage 3
            ',
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertStatus(200);
        $content = $this->stripDynamicIds(json_decode($response->json('data'), true));

        $this->assertNotNull($content);

        $expectedQuery = json_decode(file_get_contents(__DIR__ . '/files/test_query_1.json'), true);
        $this->assertEquals(
            $expectedQuery,
            $content
        );
    }

    public function test_it_can_parse_a_query_2()
    {
        $payload = [
            'query' => 'People who tested Positive for Influenza A virus and not (tested Positive for SARS-CoV-2) and measured with Leukocyte count decreased and diagnosed with Pneumonia',
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertStatus(200);
        $content = $this->stripDynamicIds(json_decode($response->json('data'), true));

        $this->assertNotNull($content);

        $expectedQuery = json_decode(file_get_contents(__DIR__ . '/files/test_query_2.json'), true);
        $this->assertEquals(
            $expectedQuery,
            $content
        );
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
