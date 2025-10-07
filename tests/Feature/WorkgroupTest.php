<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;

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

    public function test_the_application_can_search_users_by_workgroup(): void
    {
        $users = User::all();

        $elements = ['CUSTODIAN']; // in case we need more later

        foreach ($users as $u) {
            $workgroup = Workgroup::whereIn('name', $elements)->select('id')->pluck('id')->toArray();

            UserHasWorkgroup::create([
                'user_id' => $u->id,
                'workgroup_id' => fake()->randomElement($workgroup),
            ]);
        }

        $response = $this->get($this->url . '/search/users?name[]=CUSTODIAN');
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        foreach ($content['data'] as $group) {
            $this->assertTrue(in_array($group['name'], $elements));
            $this->assertTrue(count($group['users']) > 0);
        }
    }
}
