<?php

namespace Tests\Feature;

use App\Models\Custodian;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
use App\Models\User;
use DB;
use Tests\TestCase;

class CustodianNetworkTest extends TestCase
{
    private const BASE_URL = '/api/v1/custodian_networks';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        CustodianNetwork::truncate();
        CustodianNetworkHasCustodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->user = User::factory()->create();
    }

    public function test_it_can_list_custodian_networks(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        CustodianNetworkHasCustodian::create([
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
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
        $this->assertEquals($content[0]['id'], $network->id);
        $this->assertNotNull($content[0]['custodians']);
        $this->assertEquals($content[0]['custodians'][0]['id'], $custodian->id);
    }

    public function test_it_can_show_custodian_networks(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        CustodianNetworkHasCustodian::create([
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'/'.$network->id);
        $response->assertStatus(200);

        $content = $response->json('data');
        $this->assertNotNull($content);
        $this->assertEquals($content['id'], $network->id);
        $this->assertEquals($content['custodians'][0]['id'], $custodian->id);
    }

    public function test_it_can_create_custodian_network(): void
    {
        $payload = [
            'name' => 'Test Custodian Network',
        ];

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $payload);
        $response->assertStatus(201);

        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertEquals($content['name'], $payload['name']);
    }

    public function test_it_cant_create_custodian_network_without_name(): void
    {
        $payload = [];

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $payload);
        $response->assertStatus(422);
    }

    public function test_it_can_update_a_custodian_network(): void
    {
        $payload = [
            'name' => 'Test Custodian Network',
        ];

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $payload);
        $response->assertStatus(201);

        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertEquals($content['name'], $payload['name']);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->putJson(self::BASE_URL.'/'.$content['id'], [
                'name' => 'Updated Custodian',
            ]);

        $response->assertStatus(200);
        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertDatabaseHas('custodian_networks', [
            'id' => $content['id'],
            'name' => 'Updated Custodian',
        ]);
    }

    public function test_it_can_delete_custodian_network(): void
    {
        $payload = [
            'name' => 'Test Custodian Network',
        ];

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson(self::BASE_URL, $payload);
        $response->assertStatus(201);

        $contentFirst = $response->json('data');

        $this->assertNotNull($contentFirst);
        $this->assertEquals($contentFirst['name'], $payload['name']);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->deleteJson(self::BASE_URL.'/'.$contentFirst['id']);

        $response->assertStatus(200);
        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertDatabaseMissing('custodian_networks', [
            'id' => $contentFirst['id'],
            'name' => $contentFirst['name'],
        ]);
    }
}
