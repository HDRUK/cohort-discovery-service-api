<?php

namespace Tests\Feature;

use Config;
use DB;
use Str;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CustodianHasUser;
use App\Models\Custodian;
use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use App\Models\WorkgroupHasCollection;
use App\Services\QueryContext\QueryContextType;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    private const BASE_URL = '/api/v1/collections';
    private const BASE_ADMIN_URL = '/api/v1/admin/collections';
    private const CUSTODIAN_BASE_URL = '/api/v1/custodians/%s/collections';
    private const USER_COLLECTIONS_URL = '/api/v1/user/collections';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Custodian::truncate();
        CustodianHasUser::truncate();
        Collection::truncate();
        CollectionHost::truncate();
        UserHasWorkgroup::truncate();
        WorkgroupHasCollection::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->enableMiddleware();
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_it_can_list_collections(): void
    {
        $fakeGatewayTeamId = 1111;
        $anotherFakeGatewayTeamId = 2222;
        $custodian = Custodian::factory()->create([
            'external_custodian_id' => $fakeGatewayTeamId,
        ]);
        $this->user->custodians()->attach($custodian->id);

        $anotherCustodian = Custodian::factory()->create([
            'external_custodian_id' => $anotherFakeGatewayTeamId,
        ]);

        $collections = Collection::factory(5)->create([
            'custodian_id' => $custodian->id,
        ]);
        Collection::factory(5)->create([
            'custodian_id' => $anotherCustodian->id,
        ]);

        $this->attachMetadata($collections->first(), [
            'os' => 'Windows',
            'datamodel' => 'OMOP',
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(200);
        $this->assertEquals(5, count($response->json('data.data')));

        $returned = collect($response->json('data.data'))
            ->firstWhere('id', $collections->first()->id);

        $this->assertNotNull($returned);
        $this->assertArrayHasKey('latest_metadata', $returned);
        $this->assertEquals('Windows', $returned['latest_metadata']['os']);
        $this->assertEquals('OMOP', $returned['latest_metadata']['datamodel']);

        $this->user->removeRole('admin');

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $anotherCustodian->pid));

        $response->assertStatus(403);
    }

    public function test_it_cannot_list_collections_without_correct_team_admin(): void
    {
        //note - using a new user here now
        // - a test is interfering but I cant see where
        // - something must be assigning $this->user an admin role
        // - using $this->user the test works on its own, but fails when the full suite runs
        $user = User::factory()->create();
        $fakeGatewayTeamId = 1111;
        $custodian = Custodian::factory()->create([
            'external_custodian_id' => $fakeGatewayTeamId,
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id,
        ]);

        $response = $this->actingAsJwt(
            $user,
            []
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(403);
    }

    public function test_it_can_get_by_status(): void
    {
        $this->disableObservers();

        Custodian::factory()->create();

        $activeCollections = Collection::factory()->count(3)->create();
        foreach ($activeCollections as $collection) {
            $collection->setState(Collection::STATUS_ACTIVE);
        }

        $draftCollections = Collection::factory()->count(2)->create();
        foreach ($draftCollections as $collection) {
            $collection->setState(Collection::STATUS_DRAFT);
        }

        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL . '/status/' . Collection::STATUS_ACTIVE);

        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertCount(3, $content['data']);

        foreach ($content['data'] as $c) {
            $this->assertSame(Collection::STATUS_ACTIVE, data_get($c, 'model_state.state.slug'));
        }

        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_URL . '/status/' . Collection::STATUS_DRAFT);

        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertCount(2, $content['data']);

        foreach ($content['data'] as $c) {
            $this->assertSame(Collection::STATUS_DRAFT, data_get($c, 'model_state.state.slug'));
        }
    }

    public function test_it_can_show_collections(): void
    {
        Custodian::factory()->create();

        Collection::factory(10)->create();
        $coll = Collection::inRandomOrder()->first();

        $this->attachMetadata($coll, [
            'os' => 'Ubuntu',
            'datamodel' => 'OMOP 5.4',
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'/'.$coll->id);
        $response->assertStatus(200);

        $content = $response->json('data');

        $this->assertNotNull($content);
        $this->assertTrue($content['id'] === $coll->id);
        $this->assertTrue($content['name'] === $coll->name);

        $this->assertNotNull($content['latest_metadata']);
        $this->assertEquals('Ubuntu', $content['latest_metadata']['os']);
        $this->assertEquals('OMOP 5.4', $content['latest_metadata']['datamodel']);

        $this->user->removeRole('admin');

        CustodianHasUser::where('user_id', $this->user->id)->delete();

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'/'.$coll->id);
        $response->assertStatus(403);
    }

    public function test_it_can_transition_collections(): void
    {
        $this->disableObservers();

        $custodian = Custodian::factory()->create();

        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $collection->setState(Collection::STATUS_DRAFT);
        $collection->refresh()->load('modelState.state');

        $response = $this->actingAsJwt(
            $this->user,
            []
        )->putJson(
            self::BASE_URL.'/'.$collection->id.'/transition_to',
            [
                'id' => $collection->id,
                'state' => Collection::STATUS_PENDING,
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.model_state.state.slug', Collection::STATUS_PENDING);

        $collection->refresh()->load('modelState.state');
        $this->assertSame(Collection::STATUS_PENDING, $collection->modelState->state->slug);

        $this->user->removeRole('admin');

        $response = $this->actingAsJwt(
            $this->user,
            []
        )->putJson(
            self::BASE_URL.'/'.$collection->id.'/transition_to',
            [
                'id' => $collection->id,
                'state' => Collection::STATUS_ACTIVE,
            ]
        );

        $response->assertStatus(403);
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
            ->getJson(self::BASE_URL.'?name[]='.$coll->name);

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
            ->getJson(self::BASE_URL.'?sort=name:asc');
        $response->assertStatus(200);

        $content = $response->json('data.*.name');
        $sortedArray = $content;

        sort($sortedArray, SORT_STRING);
        $this->assertEquals($sortedArray, $content);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )
            ->getJson(self::BASE_URL.'?sort=name:desc');
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
            ->getJson(self::BASE_URL.'?custodian_name=Custodian%20For%20Testing');
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
            ->get(self::BASE_URL.'?status__gt=0');
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
            'external_custodian_id' => $fakeGatewayTeamId,
        ]);

        $anotherCustodian = Custodian::factory()->create([
            'external_custodian_id' => $anotherFakeGatewayTeamId,
        ]);

        Collection::factory(5)->create([
            'custodian_id' => $custodian->id,
        ]);
        Collection::factory(5)->create([
            'custodian_id' => $anotherCustodian->id,
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [
                    [
                        'id' => 1,
                        'name' => 'cohort-admin',
                    ]
                ],
                'cohort_admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name,
                    ],
                ],
            ],
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid));

        $response->assertStatus(200);
        $this->assertEquals(5, count($response->json('data.data')));

        $this->user->removeRole('admin');

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(sprintf(self::CUSTODIAN_BASE_URL, $anotherCustodian->pid));

        $response->assertStatus(403);
    }

    public function test_it_can_filter_by_model_state(): void
    {
        $this->disableObservers();

        $fakeGatewayTeamId = 1111;

        $custodian = Custodian::factory()->create([
            'external_custodian_id' => $fakeGatewayTeamId,
        ]);

        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $collection->setState(Collection::STATUS_SUSPENDED);
        $collection->refresh()->load('modelState.state');

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'cohort-admin',
                ]],
                'cohort_admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name,
                    ],
                ],
            ],
        ];

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid) . '?state=active');

        $response->assertStatus(200);
        $content = $response->json('data.data');
        $this->assertCount(0, $content);

        $collection->setState(Collection::STATUS_ACTIVE);
        $collection->refresh()->load('modelState.state');

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )->getJson(sprintf(self::CUSTODIAN_BASE_URL, $custodian->pid) . '?state=active');

        $response->assertStatus(200);
        $content = $response->json('data.data');
        $this->assertCount(1, $content);
        $this->assertSame($collection->id, $content[0]['id']);
    }

    public function test_it_can_add_and_remove_collections_from_workgroups(): void
    {
        $fakeGatewayTeamId = 1111;
        $custodian = Custodian::factory()->create([
            'external_custodian_id' => $fakeGatewayTeamId,
        ]);

        $collections = Collection::factory(5)->create([
            'custodian_id' => $custodian->id,
        ]);

        $overrides = [
            'user' => [
                'workgroups' => [[
                    'id' => 1,
                    'name' => 'cohort-admin',
                ]],
                'cohort_admin_teams' => [
                    [
                        'id' => $fakeGatewayTeamId,
                        'name' => $custodian->name,
                    ],
                ],
            ],
        ];
        $coll = $collections->random();
        $existingCollWorkgroupIds = WorkgroupHasCollection::where(['collection_id' => $coll->id])->select('workgroup_id')->get();
        $workgroup = Workgroup::whereNotIn('id', $existingCollWorkgroupIds)->inRandomOrder()->first();

        $initialNumLinkedWorkgroups = count($coll->workgroups);
        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->postJson(self::BASE_URL.'/'.$coll->id.'/workgroup', [
                'workgroup_id' => $workgroup->id,
            ]);
        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(self::BASE_URL.'/'.$coll->id);
        $response->assertStatus(200);

        $this->assertEquals($initialNumLinkedWorkgroups + 1, count($response->json('data.workgroups')));

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->deleteJson(self::BASE_URL.'/'.$coll->id.'/workgroup/'.$workgroup->id, []);
        $response->assertStatus(200);

        $response = $this->actingAsJwt(
            $this->user,
            $overrides
        )
            ->getJson(self::BASE_URL.'/'.$coll->id);

        $response->assertStatus(200);
        $this->assertEquals($initialNumLinkedWorkgroups, count($response->json('data.workgroups')));
    }

    public function test_it_can_list_all_collections_as_admin_only(): void
    {
        $custodian = Custodian::factory()->create();
        $anotherCustodian = Custodian::factory()->create();

        Collection::factory(5)->create(['custodian_id' => $custodian->id]);
        Collection::factory(5)->create(['custodian_id' => $anotherCustodian->id]);

        // Global admin can list all 10 collections
        $response = $this->actingAsJwt($this->user, [])
            ->getJson(self::BASE_ADMIN_URL);

        $response->assertStatus(200);
        $this->assertEquals(10, count($response->json('data.data')));

        // Non-admin cannot use this endpoint
        $nonAdmin = User::factory()->create();

        $response = $this->actingAsJwt($nonAdmin, [])
            ->getJson(self::BASE_ADMIN_URL);

        $response->assertStatus(403);
    }

    public function test_it_returns_metadata_in_collection_details(): void
    {
        $custodian = Custodian::factory()->create();

        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->attachMetadata($collection, [
            'os' => 'Linux',
            'biobank' => 'Demo Bank',
            'protocol' => 'Protocol Z',
        ]);

        $response = $this->actingAsJwt(
            $this->user,
            []
        )->getJson(self::BASE_URL.'/'.$collection->pid.'/details');

        $response->assertStatus(200);
        $response->assertJsonPath('data.latest_metadata.os', 'Linux');
        $response->assertJsonPath('data.latest_metadata.biobank', 'Demo Bank');
        $response->assertJsonPath('data.latest_metadata.protocol', 'Protocol Z');
    }

    // public function test_it_can_list_collections_integrated_mode(): void
    // {
    //     Config::set('system.operation_mode', 'integrated');

    //     $fakeGatewayTeamId = 1111;
    //     $anotherFakeGatewayTeamId = 2222;
    //     $custodian = Custodian::factory()->create([
    //         'external_custodian_id' => $fakeGatewayTeamId
    //     ]);

    //     $anotherCustodian = Custodian::factory()->create([
    //         'external_custodian_id' => $anotherFakeGatewayTeamId
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
    //             'cohort_admin_teams' => [
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


    // public function test_it_lists_active_collections_for_user_by_workgroup_or_custodian(): void
    // {
    //     // $this->user->removeRole('admin');
    //     $user = $this->user;

    //     $custodianA = Custodian::factory()->create();
    //     $custodianB = Custodian::factory()->create();

    //     $user->custodians()->attach($custodianA->id);

    //     $wgAllowed = Workgroup::skip(3)->first(); // non-uk-industry
    //     $wgDenied = Workgroup::skip(4)->first(); // non-uk-research

    //     $user->workgroups()->attach($wgAllowed->id);

    //     $viaCustodian = $this->makeCollectionWithState(
    //         ['custodian_id' => $custodianA->id],
    //         Collection::STATUS_ACTIVE
    //     );

    //     $viaWorkgroup = $this->makeCollectionWithState(
    //         ['custodian_id' => $custodianB->id],
    //         Collection::STATUS_ACTIVE
    //     );
    //     $viaWorkgroup->workgroups()->attach($wgAllowed->id);

    //     $notVisible = $this->makeCollectionWithState(
    //         ['custodian_id' => $custodianB->id],
    //         Collection::STATUS_ACTIVE
    //     );
    //     $notVisible->workgroups()->attach($wgDenied->id);

    //     $response = $this->actingAsJwt(
    //         $this->user,
    //         []
    //     )->getJson(self::USER_COLLECTIONS_URL);

    //     $ids  = $this->idsFromOkResponse($response);

    //     $this->assertEqualsCanonicalizing(
    //         [$viaCustodian->id, $viaWorkgroup->id],
    //         $ids
    //     );
    // }

    public function test_it_only_returns_active_collections_for_user(): void
    {
        $this->user->removeRole('admin');
        $user = $this->user;

        $custodian = Custodian::factory()->create();
        $active = $this->makeCollectionWithState(
            ['custodian_id' => $custodian->id],
            Collection::STATUS_ACTIVE
        );
        $wg = Workgroup::first();

        UserHasWorkgroup::create(['user_id' => $user->id, 'workgroup_id' => $wg->id]);
        WorkgroupHasCollection::create(['collection_id' => $active->id, 'workgroup_id' => $wg->id]);

        $draft = $this->makeCollectionWithState(
            ['custodian_id' => $custodian->id],
            Collection::STATUS_DRAFT
        );

        $response = $this->actingAsJwt(
            $this->user,
            []
        )->getJson(self::USER_COLLECTIONS_URL);

        $response->assertStatus(200);

        $ids  = $this->idsFromOkResponse($response);

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    public function test_it_returns_all_collections_for_custodian_user(): void
    {
        $this->user->removeRole('admin');

        $user = $this->user;

        $custodianA = Custodian::factory()->create();
        $custodianB = Custodian::factory()->create();

        $user->custodians()->attach($custodianA->id);

        $activeA = $this->makeCollectionWithState(
            ['custodian_id' => $custodianA->id],
            Collection::STATUS_ACTIVE
        );

        $draftA = $this->makeCollectionWithState(
            ['custodian_id' => $custodianA->id],
            Collection::STATUS_DRAFT
        );

        $activeB = $this->makeCollectionWithState(
            ['custodian_id' => $custodianB->id],
            Collection::STATUS_ACTIVE
        );

        $response = $this->actingAsJwt(
            $this->user,
            []
        )->getJson(self::USER_COLLECTIONS_URL);

        $response->assertStatus(200);

        $ids  = $this->idsFromOkResponse($response);
        $this->assertCount(2, $ids);
        $this->assertContains($activeA->id, $ids);
        $this->assertContains($draftA->id, $ids);
        $this->assertNotContains($activeB->id, $ids);
    }

    public function test_admin_gets_all_active_collections_regardless_of_workgroup_or_custodian(): void
    {
        $user =  $this->user;
        $user->assignRole('admin');

        $custodianA = Custodian::factory()->create();
        $custodianB = Custodian::factory()->create();

        $activeA = $this->makeCollectionWithState(
            ['custodian_id' => $custodianA->id],
            Collection::STATUS_ACTIVE
        );

        $activeB = $this->makeCollectionWithState(
            ['custodian_id' => $custodianB->id],
            Collection::STATUS_ACTIVE
        );

        $draftB = $this->makeCollectionWithState(
            ['custodian_id' => $custodianB->id],
            Collection::STATUS_DRAFT
        );

        $response = $response = $this->actingAsJwt(
            $this->user,
            []
        )->getJson(self::USER_COLLECTIONS_URL);

        $response->assertStatus(200);

        $ids  = $this->idsFromOkResponse($response);

        $this->assertContains($activeA->id, $ids);
        $this->assertContains($activeB->id, $ids);
        $this->assertContains($draftB->id, $ids);

        $user->removeRole('admin');
    }



    private function makeCollectionWithState(array $attrs, string $state): Collection
    {
        $collection = Collection::factory()->create($attrs);
        $collection->setState($state);

        return $collection->fresh();
    }

    private function attachMetadata(Collection $collection, array $overrides = []): void
    {
        $collection->latestMetadata()->create(array_merge([
            'os' => 'Linux',
            'bclink' => 'BC123',
            'biobank' => 'Test Biobank',
            'datamodel' => 'OMOP',
            'protocol' => 'Protocol A',
            'rounding' => '2dp',
            'threshold' => '5',
        ], $overrides));
    }

}
