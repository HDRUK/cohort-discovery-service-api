<?php

namespace Tests\Feature;

use DB;
use Tests\TestCase;
use Illuminate\Support\Str;
use App\Models\Custodian;
use App\Models\CollectionHost;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;

class CustodianTest extends TestCase
{
    private string $url = '/api/v1/custodians';

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        Custodian::truncate();
        CollectionHost::truncate();
        CustodianNetwork::truncate();
        CustodianNetworkHasCustodian::truncate();

        $this->payload = [
            'pid' => (string)Str::uuid(),
            'name' => fake()->company,
        ];
    }

    public function test_the_application_can_list_custodians(): void
    {
        $custodians = Custodian::factory(5)->create();
        $network = CustodianNetwork::factory()->create();
        foreach ($custodians as $c) {
            CollectionHost::factory()->create([
                'custodian_id' => $c->id,
            ]);

            CustodianNetworkHasCustodian::create([
                'custodian_id' => $c->id,
                'network_id' => $network->id,
            ]);
        }

        $response = $this->get($this->url);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertNotEmpty($content['data'][count($content['data']) - 1]['hosts']);
        $this->assertNotEmpty($content['data'][count($content['data']) - 1]['network']);
    }

    public function test_the_application_can_list_custodians_sorted(): void
    {
        $response = $this->get($this->url . '?sort=name:desc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        rsort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);

        $response = $this->get($this->url . '?sort=name:asc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        sort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);
    }

    public function test_the_application_can_search_custodians(): void
    {
        Custodian::factory(5)->create();

        $cust = Custodian::all()->random(1)->first();

        $response = $this->get($this->url . '?name[]=' . $cust->name);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertTrue(count($content['data']) === 1);
        $this->assertEquals($content['data'][0]['name'], $cust->name);
    }

    public function test_the_application_can_show_a_custodian(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        $host = CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        CustodianNetworkHasCustodian::create([
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);

        $response = $this->get($this->url . '/' . $custodian->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($custodian->id, $content['data']['id']);
        $this->assertNotEmpty($content['data']['hosts']);
        $this->assertNotEmpty($content['data']['network']);
    }

    public function test_the_application_can_create_a_custodian(): void
    {
        DB::table('custodians')->truncate();
        $response = $this->post($this->url, $this->payload);
        $response->assertStatus(201);

        $content = $response->json();
        $this->assertArrayHasKey('id', $content['data']);
        $this->assertEquals($this->payload['name'], $content['data']['name']);
    }

    public function test_the_application_can_update_a_custodian(): void
    {
        Custodian::factory(5)->create();

        $custodian = Custodian::all()->random();

        $updatePayload = [
            'name' => 'Updated Custodian Name',
        ];
        $response = $this->put($this->url . '/' . $custodian->id, $updatePayload);
        $response->assertStatus(200);
        $content = $response->json();
        $this->assertEquals($updatePayload['name'], $content['data']['name']);
    }

    public function test_the_application_can_delete_a_custodian(): void
    {
        Custodian::factory(5)->create();

        $custodian = Custodian::all()->random();

        $response = $this->delete($this->url . '/' . $custodian->id);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('custodians', [
            'id' => $custodian->id,
        ]);
    }

    public function test_it_can_link_a_custodian_to_a_network(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        $response = $this->post($this->url . '/' . $custodian->id . '/networks/' . $network->id);
        $response->assertStatus(200);

        $this->assertDatabaseHas('custodian_network_has_custodians', [
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);
    }

    public function test_it_can_unlink_a_custodian_to_a_network(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        $response = $this->delete($this->url . '/' . $custodian->id . '/networks/' . $network->id);
        $response->assertStatus(200);

        $this->assertDatabaseMissing('custodian_network_has_custodians', [
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);
    }
}
