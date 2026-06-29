<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use App\Models\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class OmopControllerTest extends TestCase
{
    private const SEARCH_URL = '/api/v1/omop/concepts/search';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.nlp.base_uri' => 'http://localhost:5001']);
        Http::preventStrayRequests();
    }

    private function nlpUrl(): string
    {
        return config('services.nlp.base_uri') . '/concepts/search';
    }

    private function mockNlpResponse(array $items = [], int $total = null): array
    {
        $total = $total ?? count($items);

        return [
            'total'        => $total,
            'per_page'     => 25,
            'current_page' => 1,
            'last_page'    => max(1, (int) ceil($total / 25)),
            'data'         => $items,
        ];
    }

    private function makeConcept(int $id, string $name, string $category = 'Condition'): array
    {
        return [
            'concept_id'   => $id,
            'name'         => $name,
            'category'     => $category,
            'match_score'  => 500,
            'ncollections' => 1,
            'count'        => 10,
            'children'     => [],
        ];
    }

    public function test_search_by_concept_name_forwards_term_to_nlp(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
            $this->makeConcept(24007, 'Sickle cell-thalassemia disease'),
        ]), 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['concept_name' => ['sickle']]);

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        Http::assertSent(fn ($req) => $req->data()['concept_name'] === ['sickle']);
    }

    public function test_separator_variants_are_forwarded_verbatim_to_nlp(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
        ]), 200)]);

        $query = 'sickle cell-hemoglobin';
        $this->postJson(self::SEARCH_URL, ['concept_name' => [$query]]);

        Http::assertSent(fn ($req) => $req->data()['concept_name'] === [$query]);
    }

    public function test_search_by_concept_id_forwards_id_to_nlp(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
        ]), 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['concept_id' => ['24006']]);

        $response->assertOk();
        $this->assertEquals(24006, $response->json('data.data.0.concept_id'));

        Http::assertSent(fn ($req) => in_array(24006, $req->data()['concept_id'] ?? []));
    }

    public function test_invalid_concept_id_strings_are_filtered(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse(), 200)]);

        $this->postJson(self::SEARCH_URL, ['concept_id' => ['abc', '123']]);

        Http::assertSent(fn ($req) => $req->data()['concept_id'] === [123]);
    }

    public function test_concept_name_and_concept_id_are_both_forwarded(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
            $this->makeConcept(24007, 'Sickle cell-thalassemia disease'),
            $this->makeConcept(320128, 'Essential hypertension'),
        ], 3), 200)]);

        $response = $this->postJson(self::SEARCH_URL, [
            'concept_name' => ['sickle'],
            'concept_id'   => ['320128'],
        ]);

        $response->assertOk();
        $this->assertCount(3, $response->json('data.data'));

        Http::assertSent(function ($req) {
            return $req->data()['concept_name'] === ['sickle']
                && in_array(320128, $req->data()['concept_id'] ?? []);
        });
    }

    public function test_response_shape_matches_nlp_output(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
        ]), 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['concept_name' => ['sickle']]);

        $response->assertOk();
        $item = $response->json('data.data.0');

        $this->assertArrayHasKey('concept_id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('category', $item);
        $this->assertArrayHasKey('children', $item);
        $this->assertArrayNotHasKey('description', $item);
    }

    public function test_domain_filter_forwarded_to_nlp(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(3027018, 'Heart rate', 'Measurement'),
        ]), 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['domain' => 'Measurement']);

        $response->assertOk();
        $this->assertEquals('Measurement', $response->json('data.data.0.category'));

        Http::assertSent(fn ($req) => $req->data()['domain'] === 'Measurement');
    }

    public function test_no_search_params_forwarded_with_nulls(): void
    {
        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([], 4), 200)]);

        $response = $this->postJson(self::SEARCH_URL, []);

        $response->assertOk();
        $this->assertEquals(4, $response->json('data.total'));
    }

    public function test_collections_filter_applied_when_feature_enabled(): void
    {
        Feature::activate('query-builder-use-collections-in-search');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $collection = Collection::factory()->create();

        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse([
            $this->makeConcept(3027018, 'Heart rate', 'Measurement'),
        ]), 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['collections' => [$collection->pid]]);

        $response->assertOk();

        Http::assertSent(function ($req) use ($collection) {
            $data = $req->data();
            return $data['use_collection_filter'] === true
                && in_array($collection->id, $data['collection_ids'] ?? []);
        });
    }

    public function test_collections_filter_not_applied_when_feature_disabled(): void
    {
        Feature::deactivate('query-builder-use-collections-in-search');

        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse(), 200)]);

        $this->postJson(self::SEARCH_URL, ['collections' => ['some-pid']]);

        Http::assertSent(fn ($req) => $req->data()['use_collection_filter'] === false);
    }

    public function test_stats_ordering_flag_forwarded_to_nlp(): void
    {
        Feature::activate('query-builder-use-stats-in-ordering');

        Http::fake([$this->nlpUrl() => Http::response($this->mockNlpResponse(), 200)]);

        $this->postJson(self::SEARCH_URL, []);

        Http::assertSent(fn ($req) => $req->data()['use_stats_ordering'] === true);
    }

    public function test_pagination_params_forwarded_and_paginator_built_correctly(): void
    {
        Http::fake([$this->nlpUrl() => Http::response([
            'total'        => 4,
            'per_page'     => 2,
            'current_page' => 2,
            'last_page'    => 2,
            'data'         => [
                $this->makeConcept(24007, 'Sickle cell-thalassemia disease'),
                $this->makeConcept(24006, 'Sickle cell-hemoglobin C disease'),
            ],
        ], 200)]);

        $response = $this->postJson(self::SEARCH_URL, ['per_page' => 2, 'page' => 2]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(4, $response->json('data.total'));
        $this->assertEquals(2, $response->json('data.current_page'));
        $this->assertEquals(2, $response->json('data.per_page'));

        Http::assertSent(fn ($req) => $req->data()['per_page'] === 2 && $req->data()['page'] === 2);
    }

    public function test_nlp_error_returns_error_response(): void
    {
        Http::fake([$this->nlpUrl() => Http::response('Internal Server Error', 500)]);

        $response = $this->postJson(self::SEARCH_URL, []);

        $response->assertStatus(500);
    }
}
