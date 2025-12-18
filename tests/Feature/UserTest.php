<?php

namespace Tests\Feature;

use App\Models\Query;
use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use DB;
use Tests\TestCase;

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

    public function test_the_application_can_add_users_to_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();
        $user = User::factory()->create();

        $this->url .= '/'.$user->id.'/workgroup';

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

        $this->url .= '/'.$user->id.'/workgroup/'.$workgroup->id;

        $response = $this->delete($this->url, []);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);

        $this->assertDatabaseMissing('user_has_workgroups', [
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    public function test_the_application_can_search_users(): void
    {
        $names = [
            [
                'name' => 'Zach Someone',
                'email' => 'zs@abc.com',
            ],
            [
                'name' => 'Xyz Someone',
                'email' => 'xs@abc.com',
            ],
            [
                'name' => 'Yvonne Someone',
                'email' => 'ys@abc.com',
            ],
        ];

        foreach ($names as $n) {
            $newUser = User::factory()->create($n);
        }

        $response = $this->get($this->url.'?name[]='.explode(' ', $names[0]['name'])[0]);
        $response->assertStatus(200);

        $content = $response->json();

        // dd($content['data']);
        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data'][0]['name'], $names[0]['name']);

        $response = $this->get($this->url.'?name[]='.explode(' ', $names[2]['name'])[0]);
        $response->assertStatus(200);

        $content = $response->json();
        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data'][0]['name'], $names[2]['name']);
    }

    public function test_the_application_can_search_users_or_and(): void
    {
        $names = [
            [
                'name' => 'Alice Smith',
                'email' => 'alice@example.com',
            ],
            [
                'name' => 'Bob Johnson',
                'email' => 'bob@example.com',
            ],
            [
                'name' => 'Charlie Brown',
                'email' => 'charlie@example.com',
            ],
        ];

        foreach ($names as $n) {
            $newUser = User::factory()->create($n);
        }

        $response = $this->get($this->url.'?name__or[]=Alice&name__or[]=Bob');
        $response->assertStatus(200);

        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertCount(2, $content['data']);
        $this->assertEqualsCanonicalizing(
            [
                'Alice Smith',
                'Bob Johnson',
            ],
            array_column($content['data'], 'name')
        );

        $response = $this->get($this->url.'?email__and[]=example&email__and[]=alice');
        $response->assertStatus(200);

        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertCount(1, $content['data']);
        $this->assertEquals('Alice Smith', $content['data'][0]['name']);
    }

    public function test_the_application_can_sort_users(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $names = [
            [
                'name' => 'Zach Zachson',
                'email' => 'zz@abc.com',
            ],
            [
                'name' => 'Yvonne Someone',
                'email' => 'ys@abc.com',
            ],
            [
                'name' => 'Xyz Someone',
                'email' => 'xs@abc.com',
            ],
        ];

        foreach ($names as $n) {
            $newUser = User::factory()->create($n);
        }

        $response = $this->get($this->url.'?sort=name:asc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        sort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);

        $response = $this->get($this->url.'?sort=name:desc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        rsort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);
    }
}
