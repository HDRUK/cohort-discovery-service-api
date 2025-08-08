<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

use App\Models\User;
use App\Models\Custodian;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;

class CollectionHostTest extends TestCase
{
    private string $url = '/api/v1/collection_hosts';

    protected function setUp(): void
    {
        parent::setUp();

        User::truncate();
        Collection::truncate();
        CollectionHost::truncate();
    }
    
    public function test_it_can_list_collection_hosts()
    {
        $host = CollectionHost::factory()->create();
        $collection = Collection::factory()->create();

        CollectionHostHasCollection::Create([
            'collection_host_id' => $host->id,
            'collection_id' => $collection->id,
        ]);

        $response = $this->getJson($this->url);

        $response->assertStatus(200);
        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertNotEmpty($content['data'][0]);
        $this->assertNotEmpty($content['data'][0]['collections']);
    }

    
    public function test_it_can_show_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->getJson($this->url . '/' . $host->id);

        $response->assertStatus(200)
                 ->assertJsonFragment(['id' => $host->id]);
    }

    
    public function test_it_returns_404_for_missing_collection_host()
    {
        $response = $this->getJson($this->url . '/9999');
        $response->assertStatus(404);
    }

    
    public function test_it_can_create_a_collection_host()
    {
        $data = [
            'name' => 'Host Test',
            'query_context_type' => 'bunny',
            'client_id' => 'client123',
            'client_secret' => 'secret123',
            'custodian_id' => Custodian::factory()->create()->id,
        ];

        // Create related models if needed
        Collection::factory()->create(['id' => 1]);
        User::factory()->create(['id' => 1]);

        $response = $this->postJson($this->url, $data);

        $response->assertStatus(201)
                 ->assertJsonFragment(['name' => 'Host Test']);
    }

    
    public function test_it_can_update_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->putJson($this->url . '/' . $host->id, [
            'name' => 'Updated Host'
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['name' => 'Updated Host']);
    }

    
    public function test_it_can_delete_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->deleteJson($this->url . '/' . $host->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('collection_hosts', ['id' => $host->id]);
    }
}