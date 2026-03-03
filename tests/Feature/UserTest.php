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
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_can_list_users_with_status(): void
    {
        // Create a user with a query — should have new_user_status = 0
        $userWithQuery = User::factory()->create();
        Query::factory()->create([
            'user_id' => $userWithQuery->id,
        ]);

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url);
        $response->assertStatus(200);

        $content = $response->json('data');
        $this->assertIsArray($content);

        $userWithQueryData = collect($content)->firstWhere('id', $userWithQuery->id);
        $this->assertNotNull($userWithQueryData);
        $this->assertEquals(0, $userWithQueryData['new_user_status']);

        // Create a user without queries — should have new_user_status = 1
        $newUser = User::factory()->create();
        $response = $this->actingAsJwt($this->user, [])->getJson($this->url);
        $response->assertStatus(200);

        $content = $response->json('data');
        $newUserData = collect($content)->firstWhere('id', $newUser->id);
        $this->assertNotNull($newUserData);
        $this->assertEquals(1, $newUserData['new_user_status']);
    }

    public function test_the_application_can_add_users_to_a_workgroup(): void
    {
        $workgroup = Workgroup::all()->random();
        $user = User::factory()->create();

        $response = $this->actingAsJwt($this->user, [])->postJson($this->url . '/' . $user->id . '/workgroup', [
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

        $response = $this->actingAsJwt($this->user, [])->deleteJson($this->url . '/' . $user->id . '/workgroup/' . $workgroup->id);
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

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?name[]=' . explode(' ', $names[0]['name'])[0]);
        $response->assertStatus(200);

        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertEquals($content['data'][0]['name'], $names[0]['name']);

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?name[]=' . explode(' ', $names[2]['name'])[0]);
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

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?name__or[]=Alice&name__or[]=Bob');
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

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?email__and[]=example&email__and[]=alice');
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

        // Recreate admin user after truncate
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?sort=name:asc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        sort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);

        $response = $this->actingAsJwt($this->user, [])->getJson($this->url . '?sort=name:desc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        rsort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])->getJson($this->url);
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_show_user(): void
    {
        $nonAdmin = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])->getJson($this->url . '/' . $target->id);
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_add_user_to_workgroup(): void
    {
        $nonAdmin = User::factory()->create();
        $workgroup = Workgroup::all()->random();
        $target = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])->postJson($this->url . '/' . $target->id . '/workgroup', [
            'workgroup_id' => $workgroup->id,
        ]);
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_remove_user_from_workgroup(): void
    {
        $nonAdmin = User::factory()->create();
        $workgroup = Workgroup::all()->random();
        $target = User::factory()->create();

        UserHasWorkgroup::create([
            'user_id' => $target->id,
            'workgroup_id' => $workgroup->id,
        ]);

        $response = $this->actingAsJwt($nonAdmin, [])->deleteJson($this->url . '/' . $target->id . '/workgroup/' . $workgroup->id);
        $response->assertStatus(403);
    }
}
