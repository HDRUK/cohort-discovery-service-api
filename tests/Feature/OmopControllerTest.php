<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Models\Collection;
use Laravel\Pennant\Feature;

class OmopControllerTest extends TestCase
{
    private const SEARCH_URL = '/api/v1/omop/concepts/search';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('distributions')->truncate();
        Collection::truncate();
        Collection::factory(2)->create();

        DB::table('distributions')->insert([
            [
                'collection_id' => 1,
                'name'          => 'SICKLE_CELL_C',
                'description'   => 'Sickle cell-hemoglobin C disease',
                'category'      => 'Condition',
                'concept_id'    => 24006,
                'count'         => 10,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'collection_id' => 1,
                'name'          => 'SICKLE_CELL_THAL',
                'description'   => 'Sickle cell-thalassemia disease',
                'category'      => 'Condition',
                'concept_id'    => 24007,
                'count'         => 5,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'collection_id' => 1,
                'name'          => 'HYPERTENSION',
                'description'   => 'Essential hypertension',
                'category'      => 'Condition',
                'concept_id'    => 320128,
                'count'         => 50,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'collection_id' => 2,
                'name'          => 'HEART_RATE',
                'description'   => 'Heart rate',
                'category'      => 'Measurement',
                'concept_id'    => 3027018,
                'count'         => 20,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_search_by_concept_name_returns_matching_rows(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_name' => ['sickle'],
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(2, $data);
        $names = array_column($data, 'name');
        $this->assertContains('Sickle cell-hemoglobin C disease', $names);
        $this->assertContains('Sickle cell-thalassemia disease', $names);
    }

    public function test_search_by_concept_id_returns_matching_rows(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_id' => ['24006'],
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals(24006, $data[0]['concept_id']);
    }

    public function test_concept_name_and_concept_id_are_combined_with_or(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_name' => ['sickle'],
            'concept_id'   => ['320128'],
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(3, $data);
        $ids = array_column($data, 'concept_id');
        $this->assertContains(24006, $ids);
        $this->assertContains(24007, $ids);
        $this->assertContains(320128, $ids);
    }

    public function test_multiple_values_across_params_are_combined_with_or(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_name' => ['sickle', 'hypertension'],
            'concept_id'   => ['320128'],
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(3, $data);
        $ids = array_column($data, 'concept_id');
        $this->assertContains(24006, $ids);
        $this->assertContains(24007, $ids);
        $this->assertContains(320128, $ids);
    }

    public function test_response_shape_has_no_description_field(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_name' => ['sickle'],
        ]);

        $response->assertOk();
        $item = $response->json('data.data.0');

        $this->assertArrayHasKey('concept_id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('category', $item);
        $this->assertArrayHasKey('children', $item);
        $this->assertArrayNotHasKey('description', $item);
    }

    public function test_name_field_contains_concept_name_not_distribution_name(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'concept_id' => ['24006'],
        ]);

        $response->assertOk();
        $item = $response->json('data.data.0');

        $this->assertEquals('Sickle cell-hemoglobin C disease', $item['name']);
        $this->assertEquals(24006, $item['concept_id']);
    }

    public function test_domain_filter_restricts_results(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'domain' => 'Measurement',
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals('Heart rate', $data[0]['name']);
        $this->assertEquals('Measurement', $data[0]['category']);
    }

    public function test_no_search_params_returns_all_rows(): void
    {
        $response = $this->postJson(self::SEARCH_URL, []);

        $response->assertOk();
        $this->assertEquals(4, $response->json('data.total'));
    }

    public function test_old_description_param_is_not_used_for_search(): void
    {
        $response = $this->postJson(self::SEARCH_URL, [
            'description' => 'sickle',
        ]);

        $response->assertOk();
        $this->assertEquals(4, $response->json('data.total'));
    }

    public function test_collections_filter_applied_when_feature_enabled(): void
    {
        Feature::activate('query-builder-use-collections-in-search');

        $cpid = Collection::find(2)->pid;
        $response = $this->postJson(self::SEARCH_URL, [
            'collections' => [$cpid],
        ]);

        $response->assertOk();
        $data = $response->json('data.data');

        $this->assertCount(1, $data);
        $this->assertEquals(3027018, $data[0]['concept_id']);
    }
}
