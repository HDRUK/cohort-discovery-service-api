<?php

namespace Tests\Feature;

use DB;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;
use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use Tests\TestCase;

class CollectionHostTest extends TestCase
{
    private const BASE_URL = '/api/v1/collection_hosts';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Collection::truncate();
        CollectionHost::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->enableMiddleware();
    }

    public function test_it_can_list_collection_hosts()
    {
        $host = CollectionHost::factory()->create();
        $collection = Collection::factory()->create();

        CollectionHostHasCollection::Create([
            'collection_host_id' => $host->id,
            'collection_id' => $collection->id,
        ]);

        $response = $this->actingAsJwt($this->user, [])->getJson(self::BASE_URL);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertNotEmpty($content['data'][0]);
        $this->assertNotEmpty($content['data'][0]['collections']);

        $this->user->removeRole('admin');

        $response = $this->actingAsJwt($this->user, [])->getJson(self::BASE_URL);
        $response->assertStatus(403);
    }

    public function test_it_can_show_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'/'.$host->id);

        $response->assertStatus(200)
            ->assertJsonFragment(['id' => $host->id]);

        $this->user->removeRole('admin');
        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'/'.$host->id);
        $response->assertStatus(403);
    }

    public function test_it_returns_invalid_for_missing_collection_host()
    {
        // LS - Changed to match the new failed validation rules on this class
        // 422 supersedes 404 as validation path is run first.
        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL.'/9999');
        $response->assertStatus(422);
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

        $response = $this->actingAsJwt($this->user, [])
            ->postJson(self::BASE_URL, $data);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Host Test']);

        $altUser = User::factory()->create();
        CustodianHasUser::create([
            'user_id' => $altUser->id,
            'custodian_id' => $data['custodian_id'],
        ]);

        $data['name'] = 'Host Test 2';

        $response = $this->actingAsJwt($altUser, [])
            ->postJson(self::BASE_URL, $data);
        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'Host Test 2']);
    }

    public function test_it_can_update_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->actingAsJwt($this->user, [])
            ->putJson(self::BASE_URL.'/'.$host->id, [
                'name' => 'Updated Host',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Updated Host']);
    }

    public function test_it_can_delete_a_collection_host()
    {
        $host = CollectionHost::factory()->create();

        $response = $this->actingAsJwt($this->user, [])
            ->deleteJson(self::BASE_URL.'/'.$host->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('collection_hosts', ['id' => $host->id]);
    }

    public function test_it_can_search_by_name(): void
    {
        $host = CollectionHost::factory()->create();
        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'?name[]='.str_replace(' ', '%20', $host->name));

        $content = $response->json('data');

        $this->assertNotEmpty($content);
        $this->assertEquals(count($content), 1);
        $this->assertEquals($content[0]['name'], $host->name);
    }

    public function test_it_can_sort_custodian_collection_hosts_by_name(): void
    {
        $custodian = Custodian::factory()->create();

        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Zebra Host',
        ]);
        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Alpha Host',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson('/api/v1/custodians/'.$custodian->pid.'/collection_hosts?sort=name:asc');

        $response->assertStatus(200);

        $this->assertEquals(
            ['Alpha Host', 'Zebra Host'],
            array_column($response->json('data'), 'name')
        );
    }

    public function test_it_can_sort_custodian_collection_hosts_by_name_desc(): void
    {
        $custodian = Custodian::factory()->create();

        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Alpha Host',
        ]);
        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Zebra Host',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson('/api/v1/custodians/'.$custodian->pid.'/collection_hosts?sort=name:desc');

        $response->assertStatus(200);

        $this->assertEquals(
            ['Zebra Host', 'Alpha Host'],
            array_column($response->json('data'), 'name')
        );
    }

    public function test_it_errors_for_invalid_custodian_collection_host_sort_direction(): void
    {
        $custodian = Custodian::factory()->create();

        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Alpha Host',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson('/api/v1/custodians/'.$custodian->pid.'/collection_hosts?sort=name:sideways');

        $response->assertStatus(500)
            ->assertJsonFragment(['message' => 'unexpected error'])
            ->assertJsonFragment(['data' => 'invalid sort direction sideways']);
    }

    public function test_it_errors_for_invalid_custodian_collection_host_sort_field(): void
    {
        $custodian = Custodian::factory()->create();

        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
            'name' => 'Alpha Host',
        ]);

        $response = $this->actingAsJwt($this->user, [])
            ->getJson('/api/v1/custodians/'.$custodian->pid.'/collection_hosts?sort=created_at:asc');

        $response->assertStatus(500)
            ->assertJsonFragment(['message' => 'unexpected error'])
            ->assertJsonFragment(['data' => 'field created_at is not a sortable column']);
    }
}
