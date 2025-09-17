<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;

use App\Models\User;
use App\Models\Custodian;
use App\Models\Collection;
use App\Models\CollectionHost;
use App\Models\CollectionHostHasCollection;

class CollectionTest extends TestCase
{
    private const BASE_URL  = '/api/v1/collections';
    private const CUSTODIAN_BASE_URL  = '/api/v1/custodians/%s/collections';
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        User::truncate();
        Collection::truncate();
        CollectionHost::truncate();
        $this->enableMiddleware();
        $this->user = User::factory()->create();
    }

    public function test_it_can_list_collections()
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

    public function test_it_cannot_list_collections_without_correct_workgroups()
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

    public function test_it_cannot_list_collections_without_correct_team_admin()
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
}
