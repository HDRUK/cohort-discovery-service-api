<?php

namespace Tests\Feature;

use App\Enums\ClaimTrust;
use App\Models\Custodian;
use App\Models\User;
use App\Support\ApplicationMode;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DecodeJwtIntegratedTest extends TestCase
{
    private string $url = '/api/v1/user';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('system.operation_mode', ApplicationMode::INTEGRATED);
        Config::set('integrated.jwt_secret', Config::get('api.jwt_secret', 'test_secret'));
        Config::set('claimsaccesscontrol.sync.custodians.trust', ClaimTrust::ALWAYS->value);
        Config::set('claimsaccesscontrol.sync.custodians.authoritative', true);

        $this->enableMiddleware();
        $this->user = User::factory()->create();
    }

    public function test_gateway_teams_with_integer_ids_are_synced_as_custodians(): void
    {
        $response = $this->actingAsJwt($this->user, [
            'user' => [
                'cohort_admin_teams' => [
                    ['id' => 21, 'name' => 'Acme Trust'],
                    ['id' => 22, 'name' => 'Beta Trust'],
                ],
            ],
        ])->getJson($this->url);

        $response->assertOk();
        $this->assertSame(
            ['21', '22'],
            Custodian::whereIn('name', ['Acme Trust', 'Beta Trust'])->orderBy('external_custodian_id')->pluck('external_custodian_id')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['Acme Trust', 'Beta Trust'],
            $this->user->custodians()->pluck('name')->all()
        );
    }
}
