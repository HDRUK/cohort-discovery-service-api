<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

use App\Models\Workgroup;

class WorkgroupTest extends TestCase
{
    private string $url = '/api/v1/workgroups';

    public function test_the_application_can_list_workgroups(): void
    {
        $response = $this->get($this->url);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
    }

    public function test_the_application_can_show_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();

        $response = $this->get($this->url . '/' . $workgroup->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($workgroup->id, $content['data']['id']);
    }

    public function test_the_application_can_create_a_workgroup(): void
    {
        $payload = [
            'name' => 'New Workgroup',
            'active' => true,
        ];

        $response = $this->post($this->url, $payload);
        $response->assertStatus(201);

        $content = $response->json();
        $this->assertArrayHasKey('id', $content['data']);
        $this->assertEquals($payload['name'], $content['data']['name']);
    }

    public function test_the_application_can_update_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();

        $payload = [
            'name' => 'Updated Workgroup',
            'active' => false,
        ];

        $response = $this->put($this->url . '/' . $workgroup->id, $payload);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($payload['name'], $content['data']['name']);
        $this->assertFalse($content['data']['active']);
    }

    public function test_the_application_can_delete_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();

        $response = $this->delete($this->url . '/' . $workgroup->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEmpty($content['data']);

        $response = $this->get($this->url . '/' . $workgroup->id);
        $response->assertStatus(404);
    }
}
