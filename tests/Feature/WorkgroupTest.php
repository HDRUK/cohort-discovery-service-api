<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use DB;
use Tests\TestCase;

class WorkgroupTest extends TestCase
{
    private string $url = '/api/v1/workgroups';
    private User $user;

    private array $workgroups = [
        'ADMIN',
        'DEFAULT',
        'CUSTODIAN',
        'NON-UK-INDUSTRY',
        'NON-UK-RESEARCH',
        'OTHER',
        'UK-INDUSTRY',
        'UK-RESEARCH',
        'NHS-SDE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Workgroup::truncate();

        foreach ($this->workgroups as $w) {
            Workgroup::create([
                'name' => $w,
                'active' => true,
            ]);
        }

        $this->enableMiddleware();
        $this->user = User::factory()->create();

        $this->user->assignRole('admin');
    }

    public function test_the_application_can_list_workgroups(): void
    {
        $this->user->removeRole('admin');
        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url);
        $response->assertStatus(403);

        $this->user->assignRole('admin');
        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url);

        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
    }

    public function test_the_application_can_show_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();

        $this->user->removeRole('admin');
        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url);
        $response->assertStatus(403);

        $this->user->assignRole('admin');

        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url.'/'.$workgroup->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($workgroup->id, $content['data']['id']);
    }

    public function test_the_application_can_create_a_workgroup_with_admin_roles(): void
    {
        $this->user->removeRole('admin');

        $payload = [
            'name' => 'New Workgroup',
            'active' => true,
        ];

        $response = $this->actingAsJwt($this->user, [])
            ->postJson($this->url, $payload);
        $response->assertStatus(403);

        $this->user->assignRole('admin');

        $response = $this->actingAsJwt($this->user, [])
            ->postJson($this->url, $payload);
        $response->assertStatus(201);

        $content = $response->json();
        $this->assertArrayHasKey('id', $content['data']);
        $this->assertEquals($payload['name'], $content['data']['name']);
    }

    public function test_the_application_can_update_a_workgroup(): void
    {
        $this->user->removeRole('admin');
        $workgroup = Workgroup::all()->random();

        $payload = [
            'name' => 'Updated Workgroup',
            'active' => false,
        ];

        $response = $this->actingAsJwt($this->user, [])
            ->putJson($this->url.'/'.$workgroup->id, $payload);
        $response->assertStatus(403);

        $this->user->assignRole('admin');
        $response = $this->actingAsJwt($this->user, [])
            ->putJson($this->url.'/'.$workgroup->id, $payload);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEquals($payload['name'], $content['data']['name']);
        $this->assertFalse($content['data']['active']);
    }

    public function test_the_application_can_delete_a_workgroup(): void
    {
        $this->user->removeRole('admin');
        $workgroup = Workgroup::all()->random();

        $response = $this->actingAsJwt($this->user, [])
            ->deleteJson($this->url.'/'.$workgroup->id);
        $response->assertStatus(403);

        $this->user->assignRole('admin');
        $response = $this->actingAsJwt($this->user, [])
            ->deleteJson($this->url.'/'.$workgroup->id);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertEmpty($content['data']);
    }

    public function test_only_admin_can_search_users_by_workgroup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        UserHasWorkgroup::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        User::factory()->count(5)->create();
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $users = User::all();

        foreach ($users as $u) {
            $uhw = UserHasWorkgroup::create([
                'user_id' => $u->id,
                'workgroup_id' => 2,
            ]);

            $this->assertDatabaseHas('user_has_workgroups', [
                'user_id' => $u->id,
                'workgroup_id' => 2,
            ]);
        }

        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url.'/search/users?name[]=DEFAULT');
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        foreach ($content['data'] as $group) {
            $this->assertTrue(in_array($group['name'], $this->workgroups));
            $this->assertTrue(count($group['users']) > 0);
        }

        $this->user->removeRole('admin');

        $response = $this->actingAsJwt($this->user, [])
            ->getJson($this->url.'/search/users?name[]=DEFAULT');
        $response->assertStatus(403);
    }
}
