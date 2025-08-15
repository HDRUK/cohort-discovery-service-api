<?php

namespace Tests\Feature;

use DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\Custodian;
use App\Models\CollectionHost;

class CustodianTest extends TestCase
{
    private string $url = '/api/v1/custodians';

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payload = [
            'name' => fake()->company,
            'street_address' => fake()->streetAddress,
            'city' => fake()->city,
            'postal_code' => fake()->postcode,
            'phone' => fake()->phoneNumber,
            'country' => fake()->country,
            'url' => fake()->url,
            'email' => fake()->unique()->safeEmail,
            'user_id' => null,
        ];
    }

    public function test_the_application_can_list_custodians(): void
    {
        $response = $this->get($this->url);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertNotEmpty($content['data'][count($content['data']) -1]['hosts']);
    }

    public function test_the_application_can_show_a_custodian(): void
    {
        $custodian = Custodian::factory()->create();
        $host = CollectionHost::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $response = $this->get($this->url . '/' . $custodian->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($custodian->id, $content['data']['id']);
        $this->assertNotEmpty($content['data']['hosts']);
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
}
