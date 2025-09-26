<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Query;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;

class UserTest extends TestCase
{
    private string $url = '/api/v1/users';

    public function test_can_list_users_with_status(): void
    {
        // Get an existing user
        $user = User::factory()->create();
        Query::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->get($this->url);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        foreach ($content['data'] as $u) {
            $this->assertEquals($u['new_user_status'], 0);
        }

        // Get a new user
        $newUser = User::factory()->create();
        $response = $this->get($this->url);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data'][$newUser->id - 1]['new_user_status'], 1);
    }

    public function test_can_show_user_with_status(): void
    {
        // Get an existing user
        $user = User::factory()->create();
        Query::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->get($this->url . '/' . $user->id);
        $response->assertStatus(200);

        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data']['new_user_status'], 0);

        // Get a new user
        $newUser = User::factory()->create();
        $response = $this->get($this->url . '/' . $newUser->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data']['new_user_status'], 1);
    }

    public function test_the_application_can_add_users_to_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();
        $user = User::factory()->create();

        $this->url .= '/' . $user->id . '/workgroup/add';

        $response = $this->post($this->url, [
            'workgroup_id' => $workgroup->id,
        ]);

        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertDatabaseHas('user_has_workgroups', [
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    public function test_the_application_can_remove_users_from_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();
        $user = User::factory()->create();

        // First, add the user to the workgroup
        UserHasWorkgroup::create([
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);

        $this->url .= '/' . $user->id . '/workgroup/remove';

        $response = $this->post($this->url, [
            'workgroup_id' => $workgroup->id,
        ]);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        $this->assertDatabaseMissing('user_has_workgroups', [
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);
    }
}
