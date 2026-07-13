<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmopControllerTest extends TestCase
{
    private const SEARCH_URL = '/api/v1/omop/concepts/search';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp.base_uri' => 'http://localhost:5001']);
        Http::preventStrayRequests();
    }

    public function test_separator_variants_match_hyphenated_concept_name(): void
    private function nlpUrl(): string
    {
        return config('services.nlp.base_uri') . '/concepts/search';
    }

    public function test_returns_ok_when_nlp_succeeds(): void
    {
        Http::fake([$this->nlpUrl() => Http::response([
            'total'        => 1,
            'per_page'     => 25,
            'current_page' => 1,
            'last_page'    => 1,
            'data'         => [['concept_id' => 320128, 'name' => 'Essential hypertension', 'category' => 'Condition', 'match_score' => 500, 'ncollections' => 1, 'count' => 50, 'children' => []]],
        ], 200)]);

        $this->postJson(self::SEARCH_URL, ['concept_name' => ['hypertension']])->assertOk();
    }

    public function test_returns_error_when_nlp_fails(): void
    {
        Http::fake([$this->nlpUrl() => Http::response('Internal Server Error', 500)]);

        $this->postJson(self::SEARCH_URL, [])->assertStatus(500);
    }
}
