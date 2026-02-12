<?php

namespace Tests\Feature;

use DB;
use App\Models\User;
use App\Models\CollectionHost;
use App\Models\Custodian;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
use App\Models\CustodianHasUser;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustodianTest extends TestCase
{
    private string $url = '/api/v1/custodians';

    private array $payload;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Custodian::truncate();
        CollectionHost::truncate();
        CustodianNetwork::truncate();
        CustodianNetworkHasCustodian::truncate();

        $this->enableMiddleware();
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->payload = [
            'pid' => (string) Str::uuid(),
            'name' => fake()->company,
        ];
    }

    public function test_the_application_can_list_custodians(): void
    {
        $this->user->removeRole('admin');

        $fakeGatewayId = 1111;
        $anotherFakeGatewayId = 2222;

        $custodian = Custodian::factory()->create([
            'external_custodian_id' => $fakeGatewayId,
        ]);

        $anotherCustodian = Custodian::factory()->create([
            'external_custodian_id' => $anotherFakeGatewayId,
        ]);

        $network = CustodianNetwork::factory()->create();

        CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        CollectionHost::factory()->create([
            'custodian_id' => $anotherCustodian->id,
        ]);

        CustodianNetworkHasCustodian::create([
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);

        CustodianNetworkHasCustodian::create([
            'custodian_id' => $anotherCustodian->id,
            'network_id' => $network->id,
        ]);

        $this->user->custodians()->attach($custodian->id);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'admin',
                    'enabled' => 1,
                ]],
            ],
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson($this->url);

        $response->assertStatus(200);

        $this->user->assignRole('admin');

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson($this->url);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertNotEmpty($content['data'][count($content['data']) - 1]['hosts']);
        $this->assertNotEmpty($content['data'][count($content['data']) - 1]['network']);
    }

    public function test_the_application_can_list_custodians_sorted(): void
    {
        $response = $this->actingAsJwt($this->user, [])->getJson($this->url.'?sort=name:desc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        rsort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url.'?sort=name:asc');
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

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson($this->url.'?name[]='.$cust->name);
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

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson($this->url.'/'.$custodian->id);

        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($custodian->id, $content['data']['id']);
        $this->assertNotEmpty($content['data']['hosts']);
        $this->assertNotEmpty($content['data']['network']);
    }

    public function test_the_application_can_create_a_custodian(): void
    {
        DB::table('custodians')->truncate();
        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson($this->url, $this->payload);

        $response->assertStatus(201);

        $content = $response->json();

        $this->assertArrayHasKey('id', $content['data']);
        $this->assertEquals($this->payload['name'], $content['data']['name']);
    }

    public function test_only_admin_can_create_a_custodian(): void
    {
        DB::table('custodians')->truncate();

        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt(
            $nonAdmin,
            []
        )
            ->postJson($this->url, $this->payload);
        $response->assertStatus(403);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson($this->url, $this->payload);
        $response->assertStatus(201);
    }

    public function test_the_application_can_update_a_custodian(): void
    {
        Custodian::factory(5)->create();

        $custodian = Custodian::all()->random();

        $updatePayload = [
            'name' => 'Updated Custodian Name',
        ];
        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->putJson($this->url.'/'.$custodian->id, $updatePayload);

        $response->assertStatus(200);
        $content = $response->json();
        $this->assertEquals($updatePayload['name'], $content['data']['name']);
    }

    public function test_only_admin_can_delete_a_custodian(): void
    {
        $custodian = Custodian::factory()->create();

        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt(
            $nonAdmin,
            []
        )
            ->deleteJson($this->url.'/'.$custodian->id);
        $response->assertStatus(403);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->deleteJson($this->url.'/'.$custodian->id);
        $response->assertStatus(200);
    }

    public function test_the_application_can_delete_a_custodian(): void
    {
        Custodian::factory(5)->create();

        $custodian = Custodian::all()->random();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->deleteJson($this->url.'/'.$custodian->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('custodians', [
            'id' => $custodian->id,
        ]);
    }

    public function test_it_can_link_a_custodian_to_a_network(): void
    {
        $custodian = Custodian::factory()->create();
        $network = CustodianNetwork::factory()->create();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->postJson($this->url.'/'.$custodian->id.'/networks/'.$network->id);

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

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->deleteJson($this->url.'/'.$custodian->id.'/networks/'.$network->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('custodian_network_has_custodians', [
            'custodian_id' => $custodian->id,
            'network_id' => $network->id,
        ]);
    }

    public function test_one_custodian_cannot_update_another_custodian(): void
    {
        $custodians = Custodian::factory(2)->create();

        $user1 = User::factory()->create();
        CustodianHasUser::create([
            'user_id' => $user1->id,
            'custodian_id' => $custodians[0]->id,
        ]);

        $user2 = User::factory()->create();
        CustodianHasUser::create([
            'user_id' => $user2->id,
            'custodian_id' => $custodians[1]->id,
        ]);

        $response = $this->actingAsJwt(
            $user1,
            []
        )
            ->putJson($this->url.'/'.$custodians[0]->id, [
                'name' => 'Updated Custodian One',
            ]);
        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $user2,
            []
        )
            ->putJson($this->url.'/'.$custodians[1]->id, [
                'name' => 'Updated Custodian Two',
            ]);
        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $user1,
            []
        )
            ->putJson($this->url.'/'.$custodians[1]->id, [
                'name' => 'Should Not Update Two',
            ]);
        $response->assertStatus(403);

        $response = $this->actingAsJwt(
            $user2,
            []
        )
            ->putJson($this->url.'/'.$custodians[0]->id, [
                'name' => 'Should Not Update One',
            ]);
        $response->assertStatus(403);
    }

    public function test_one_custodian_cannot_see_another_custodian(): void
    {
        $custodians = Custodian::factory(2)->create();

        $user1 = User::factory()->create();
        CustodianHasUser::create([
            'user_id' => $user1->id,
            'custodian_id' => $custodians[0]->id,
        ]);

        $user2 = User::factory()->create();
        CustodianHasUser::create([
            'user_id' => $user2->id,
            'custodian_id' => $custodians[1]->id,
        ]);

        $response = $this->actingAsJwt(
            $user1,
            []
        )
            ->getJson($this->url .'/'.$custodians[0]->id);

        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $user2,
            []
        )
            ->getJson($this->url .'/'.$custodians[1]->id);
        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $user1,
            []
        )
            ->getJson($this->url .'/'.$custodians[1]->id);
        $response->assertStatus(403);

        $response = $this->actingAsJwt(
            $user2,
            []
        )
            ->getJson($this->url .'/'.$custodians[0]->id);
        $response->assertStatus(403);
    }
}
