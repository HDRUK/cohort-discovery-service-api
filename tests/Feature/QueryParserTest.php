<?php

namespace Tests\Feature;

use Tests\TestCase;

class QueryParserTest extends TestCase
{
    private const BASE_URL = '/api/v1/parse-query';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_it_can_parse_a_query()
    {
        $payload = [
            'query' => '
                People who received Moderna COVID-19 vaccine or Pfizer COVID-19 vaccine and received not
                (received Oxford, AstraZeneca COVID-19 vaccine) and observed with Close contact with
                confirmed COVID-19 case person and measured with SARS-CoV-2 antibody to nucleocapsid
                (N) protein present and diagnosed with Chronic kidney disease stage 3
            ',
        ];

        $response = $this->postJson(self::BASE_URL, $payload);

        $response->assertStatus(200);
        $content = $response->json('data');
        
        dd($content);
    }
}