<?php

namespace Tests\Feature;

use DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\Collection;
use App\Models\CollectionConfig;

class CollectionConfigTest extends TestCase
{
    private const BASE_URL  = '/api/v1/collection_config';
    private User $user;

    private array $payload = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Collection::truncate();
        CollectionConfig::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->user = User::factory()->create();
        $this->payload = CollectionConfig::factory()->definition();
    }

    public function test_it_can_list_collection_config(): void
    {
        $collection = Collection::factory()->create();

        CollectionConfig::factory()->create([
            'collection_id' => $collection->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL);
        $response->assertStatus(200);

        $content = $response->json('data');
        $this->assertNotNull($content);
        $this->assertTrue(count($content) > 0);
        $this->assertTrue($content[0]['collection_id'] === $collection->id);
    }

    public function test_it_can_show_collection_config(): void
    {
        $collection = Collection::factory()->create();

        $config = CollectionConfig::factory()->create([
            'collection_id' => $collection->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '/' . $config->id);
        $response->assertStatus(200);

        $content = $response->json('data');
        $this->assertNotNull($content);
        $this->assertTrue($content['collection_id'] === $collection->id);
    }

    public function test_it_can_create_collection_config(): void
    {
        $collection = Collection::factory()->create();

        $this->payload['collection_id'] = $collection->id;

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $this->payload);
        $response->assertStatus(201);

        $content = $response->json('data');
        $this->assertNotNull($content);
        $this->assertTrue($content['collection_id'] === $collection->id);
    }

    public function test_it_can_update_collection_config(): void
    {
        $collection = Collection::factory()->create();

        $this->payload['collection_id'] = $collection->id;

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $this->payload);
        $response->assertStatus(201);

        $content = $response->json('data');
        $this->assertNotNull($content);
        $this->assertTrue($content['collection_id'] === $collection->id);

        $this->payload['run_time_hour'] = 12;
        $this->payload['run_time_minute'] = 30;
        $this->payload['type'] = ($this->payload['type'] === 'a' ? 'b' : 'a');

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->putJson(self::BASE_URL . '/' . $content['id'], $this->payload);
        $response->assertStatus(200);

        $this->assertDatabaseHas('collection_config', [
            'collection_id' => $collection->id,
            'run_time_hour' => 12,
            'run_time_minute' => 30,
            'type' => $this->payload['type'],
        ]);
    }

    public function test_it_can_delete_collection_config(): void
    {
        $collection = Collection::factory()->create();
        $config = CollectionConfig::factory()->create();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->deleteJson(self::BASE_URL . '/' . $config->id);
        $response->assertStatus(200);

        $this->assertNull(CollectionConfig::where('id', $config->id)->first());
    }
}
