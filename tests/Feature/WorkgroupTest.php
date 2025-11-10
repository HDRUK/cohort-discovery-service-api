<?php

namespace Tests\Feature;

use DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;

class WorkgroupTest extends TestCase
{
    private string $url = '/api/v1/workgroups';
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

    public function setUp(): void
    {
        parent::setUp();

        Workgroup::truncate();

        foreach ($this->workgroups as $w) {
            Workgroup::create([
                'name' => $w,
                'active' => true,
            ]);
        }
    }

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
    }

    public function test_the_application_can_search_users_by_workgroup(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        User::factory()->count(5)->create();

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

        $response = $this->get($this->url . '/search/users?name[]=DEFAULT');
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        foreach ($content['data'] as $group) {
            $this->assertTrue(in_array($group['name'], $this->workgroups));
            $this->assertTrue(count($group['users']) > 0);
        }
    }
}
