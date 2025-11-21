<?php

namespace Tests\Feature;

use DB;
use Str;
use Config;
use Tests\TestCase;
use App\Models\User;
use App\Models\Custodian;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Services\QueryContext\QueryContextType;

class CollectionTest extends TestCase
{
    private const BASE_URL  = '/api/v1/collections';
    private const CUSTODIAN_BASE_URL  = '/api/v1/custodians/%s/collections';
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Custodian::truncate();
        Collection::truncate();
        CollectionHost::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->enableMiddleware();
        $this->user = User::factory()->create();
    }

    public function test_it_can_list_collections(): void
    {
        $fakeGatewayTeamId = 1111;
        $anotherFakeGatewayTeamId = 2222;
        $custodian = Custodian::factory()->create([
            'gateway_team_id' => $fakeGatewayTeamId
        ]);

        $anotherCustodian = Custodian::factory()->create([
            'gateway_team_id' => $anotherFakeGatewayTeamId
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id
        ]);
        Collection::factory(5)->create([
            'custodian_id' => $anotherCustodian->id
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'cohort-admin'
                ]],
                'admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name
                    ]
                ]
            ]
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(200);
        $this->assertEquals(5, count($response->json('data.data')));


        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $anotherCustodian->pid));

        $response->assertStatus(403);
    }

    public function test_it_cannot_list_collections_without_correct_workgroups(): void
    {
        $fakeGatewayTeamId = 1111;
        $custodian = Custodian::factory()->create([
            'gateway_team_id' => $fakeGatewayTeamId
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'unknown-workgroup'
                ]],
                'admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name
                    ]
                ]
            ]
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(401);
    }

    public function test_it_cannot_list_collections_without_correct_team_admin(): void
    {
        $fakeGatewayTeamId = 1111;
        $custodian = Custodian::factory()->create([
            'gateway_team_id' => $fakeGatewayTeamId
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'cohort-admin'
                ]],
                'admin_teams' => []
            ]
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(403);
    }

    public function test_it_can_get_by_status(): void
    {
        Custodian::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            Collection::factory()->create([
                'status' => ($i % 2 ? 1 : 0),
            ]);
        }

        $this->assertDatabaseHas('collections', [
            'status' => 1,
        ]);

        $this->assertDatabaseHas('collections', [
            'status' => 0,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '/status/' . Collection::STATUS_ACTIVE);
        $response->assertStatus(200);

        $content = $response->json('data');

        foreach ($content['data'] as $c) {
            $this->assertTrue($c['status'] === 1);
        }

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '/status/' . Collection::STATUS_INACTIVE);
        $response->assertStatus(200);

        $content = $response->json('data');

        foreach ($content['data'] as $c) {
            $this->assertTrue($c['status'] === 0);
        }
    }

    public function test_it_can_search_by_name(): void
    {
        Custodian::factory()->create();

        Collection::factory(10)->create();
        $coll = Collection::inRandomOrder()->first();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '?name[]=' . $coll->name);
        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertTrue(count($content) === 1);
        $this->assertTrue($content[0]['id'] === $coll->id);
        $this->assertTrue($content[0]['name'] === $coll->name);
    }

    public function test_it_can_sort(): void
    {
        Custodian::factory()->create();

        Collection::factory(10)->create();
        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '?sort=name:asc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        sort($sortedArray, SORT_STRING);
        $this->assertEquals($sortedArray, $content);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '?sort=name:desc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        rsort($sortedArray, SORT_STRING);

        $this->assertEquals($sortedArray, $content);
    }

    public function test_it_can_search_custodian_name(): void
    {
        $this->disableObservers();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $custodian = Custodian::factory()->create([
            'name' => 'Custodian For Testing',
        ]);

        Custodian::factory(1)->create([
            'name' => 'Not A Custodian For Testing',
        ]);

        Collection::factory(1)->create([
            'custodian_id' => $custodian->id,
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL . '?custodian_name=Custodian%20For%20Testing');
        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertTrue(count($content) > 0);
        $this->assertTrue($content[0]['id'] === $custodian->id);
    }

    public function test_it_can_filter_collections(): void
    {
        Custodian::factory()->create();

        $collections = [
            [
                'name' => 'Collection One',
                'url' => fake()->url,
                'pid' => Str::uuid(),
                'type' => QueryContextType::Bunny,
                'custodian_id' => 1,
                'status' => 1,
            ],
            [
                'name' => 'Collection Two',
                'url' => fake()->url,
                'pid' => Str::uuid(),
                'type' => QueryContextType::Bunny,
                'custodian_id' => 1,
                'status' => 0,
            ],
            [
                'name' => 'Collection Three',
                'url' => fake()->url,
                'pid' => Str::uuid(),
                'type' => QueryContextType::Bunny,
                'custodian_id' => 1,
                'status' => 1,
            ],
            [
                'name' => 'Collection Four',
                'url' => fake()->url,
                'pid' => Str::uuid(),
                'type' => QueryContextType::Bunny,
                'custodian_id' => 1,
                'status' => 0,
            ],
        ];

        foreach ($collections as $c) {
            Collection::factory()->create($c);
        }

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->get(self::BASE_URL . '?status__gt=0');
        $response->assertStatus(200);

        $content = $response->json();

        $this->assertIsArray($content['data']);
        $this->assertCount(2, $content['data']);
        foreach ($content['data'] as $d) {
            $this->assertEquals(1, $d['status']);
        }
    }

    /**
     * Once standalone mode was completed, the initial test_it_can_list_collections
     * test failed on decoding jwt and checking claims. The following tests are to
     * cover edge-cases of both standalone and integrated modes.
     */
    public function test_it_can_list_collections_standalone_mode(): void
    {
        Config::set('system.operation_mode', 'standalone');

        $fakeGatewayTeamId = 1111;
        $anotherFakeGatewayTeamId = 2222;
        $custodian = Custodian::factory()->create([
            'gateway_team_id' => $fakeGatewayTeamId
        ]);

        $anotherCustodian = Custodian::factory()->create([
            'gateway_team_id' => $anotherFakeGatewayTeamId
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id
        ]);
        Collection::factory(5)->create([
            'custodian_id' => $anotherCustodian->id
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'cohort-admin'
                ]],
                'admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name
                    ]
                ]
            ]
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));
        // dd($response);

        $response->assertStatus(200);
        $this->assertEquals(5, count($response->json('data.data')));


        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $anotherCustodian->pid));

        $response->assertStatus(403);
    }

    // public function test_it_can_list_collections_integrated_mode(): void
    // {
    //     Config::set('system.operation_mode', 'integrated');

    //     $fakeGatewayTeamId = 1111;
    //     $anotherFakeGatewayTeamId = 2222;
    //     $custodian = Custodian::factory()->create([
    //         'gateway_team_id' => $fakeGatewayTeamId
    //     ]);

    //     $anotherCustodian = Custodian::factory()->create([
    //         'gateway_team_id' => $anotherFakeGatewayTeamId
    //     ]);

    //     Collection::factory(5)->create([
    //         'custodian_id' => $custodian->id
    //     ]);
    //     Collection::factory(5)->create([
    //         'custodian_id' => $anotherCustodian->id
    //     ]);

    //     $overrides = [
    //         'user' => [
    //             'workgroups' => [[
    //                 'id' => 1,
    //                 'name' => 'cohort-admin'
    //             ]],
    //             'admin_teams' => [
    //                 [
    //                     'id' => $fakeGatewayTeamId,
    //                     'name' => $custodian->name
    //                 ]
    //             ]
    //         ]
    //     ];

    //     $response = $this->actingAsJwt(
    //         $this->user,
    //         $overrides
    //     )
    //         ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));
    //     // dd($response);

    //     $response->assertStatus(200);
    //     $this->assertEquals(5, count($response->json('data.data')));


    //     $response = $this->actingAsJwt(
    //         $this->user,
    //         $overrides
    //     )
    //         ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $anotherCustodian->pid));

    //     $response->assertStatus(403);
    // }

    // public function test_the_application_can_group_by_custodian(): void
    // {
    //     $custodians = Custodian::factory()->count(2)->create();

    //     $collections = [
    //         [
    //             'name' => 'Collection One',
    //             'url' => fake()->url,
    //             'pid' => Str::uuid(),
    //             'type' => QueryContextType::Bunny,
    //             'custodian_id' => $custodians[0]->id,
    //             'status' => 1,
    //         ],
    //         [
    //             'name' => 'Collection Two',
    //             'url' => fake()->url,
    //             'pid' => Str::uuid(),
    //             'type' => QueryContextType::Bunny,
    //             'custodian_id' => $custodians[0]->id,
    //             'status' => 0,
    //         ],
    //         [
    //             'name' => 'Collection Three',
    //             'url' => fake()->url,
    //             'pid' => Str::uuid(),
    //             'type' => QueryContextType::Bunny,
    //             'custodian_id' => $custodians[0]->id,
    //             'status' => 1,
    //         ],
    //         [
    //             'name' => 'Collection Four',
    //             'url' => fake()->url,
    //             'pid' => Str::uuid(),
    //             'type' => QueryContextType::Bunny,
    //             'custodian_id' => $custodians[1]->id,
    //             'status' => 0,
    //         ],
    //     ];

    //     $response = $this->actingAsJwt(
    //         $this->user,
    //         []
    //     )
    //         ->getJson(self::BASE_URL . '?group_by=custodian.id');
    //     $response->assertStatus(200);

    //     $content = $response->json();

    //     $this->assertCount(2, $content['data']);
    //     $this->assertEqualsCanonicalizing(
    //         [3, 1],
    //         array_column($content['data'], 'total')
    //     );
    // }

}
