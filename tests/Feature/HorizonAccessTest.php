<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateHorizonServiceAccount;
use App\Models\ServiceAccount;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    private const CLIENT_ID = 'horizon-client';

    private const CLIENT_SECRET = 'horizon-secret';

    protected function setUp(): void
    {
        parent::setUp();

        ServiceAccount::truncate();
        Feature::purge(AuthenticateHorizonServiceAccount::PROTECTION_FEATURE);
        $this->useEnvironment('production');

        $this->enableMiddleware();
    }

    public function tearDown(): void
    {
        Feature::purge(AuthenticateHorizonServiceAccount::PROTECTION_FEATURE);

        parent::tearDown();
    }

    private function useEnvironment(string $environment): void
    {
        config(['app.env' => $environment]);
        $this->app['env'] = $environment;
    }

    private function createHorizonAccount(): ServiceAccount
    {
        return ServiceAccount::factory()->create([
            'name' => ServiceAccount::HORIZON,
            'client_id' => self::CLIENT_ID,
            'client_secret' => self::CLIENT_SECRET,
        ]);
    }

    public function test_no_credentials_are_challenged_for_basic_auth(): void
    {
        $this->createHorizonAccount();

        $this->get('/horizon')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Horizon (username: client ID, password: client secret)"');
    }

    public function test_a_wrong_secret_is_challenged(): void
    {
        $this->createHorizonAccount();

        $this->withBasicAuth(self::CLIENT_ID, 'wrong')->get('/horizon')->assertUnauthorized();
    }

    public function test_an_unknown_client_id_is_challenged(): void
    {
        $this->createHorizonAccount();

        $this->withBasicAuth('unknown', self::CLIENT_SECRET)->get('/horizon')->assertUnauthorized();
    }

    public function test_the_horizon_credentials_are_challenged_when_the_account_does_not_exist(): void
    {
        $this->withBasicAuth(self::CLIENT_ID, self::CLIENT_SECRET)->get('/horizon')->assertUnauthorized();
    }

    public function test_another_service_accounts_valid_credentials_are_denied(): void
    {
        ServiceAccount::factory()->create([
            'name' => 'scheduler',
            'client_id' => 'scheduler-client',
            'client_secret' => 'scheduler-secret',
        ]);

        $this->withBasicAuth('scheduler-client', 'scheduler-secret')->get('/horizon')->assertUnauthorized();
    }

    public function test_disabling_client_basic_auth_does_not_open_horizon(): void
    {
        config(['system.basic_auth_enabled' => false]);

        $this->get('/horizon')->assertUnauthorized();
    }

    public function test_credentials_in_the_query_string_are_ignored(): void
    {
        $this->createHorizonAccount();

        $this->get('/horizon?key='.self::CLIENT_SECRET.'&token='.self::CLIENT_SECRET)->assertUnauthorized();
    }

    public function test_staging_is_protected_like_production(): void
    {
        $this->useEnvironment('staging');

        $this->get('/horizon')->assertUnauthorized();
    }

    public function test_local_is_open_without_credentials(): void
    {
        $this->useEnvironment('local');

        $this->get('/horizon')->assertOk();
    }

    public function test_the_horizon_service_account_is_allowed(): void
    {
        $this->createHorizonAccount();

        $this->withBasicAuth(self::CLIENT_ID, self::CLIENT_SECRET)->get('/horizon')->assertOk();
    }

    public function test_horizon_is_open_when_protection_is_switched_off(): void
    {
        Feature::deactivate('horizon-protection');

        $this->get('/horizon')->assertOk();
    }

    public function test_protection_is_on_by_default(): void
    {
        $this->assertTrue(Feature::active('horizon-protection'));
    }

    public function test_the_protection_flag_cannot_be_overridden(): void
    {
        $this->assertFalse(config('features.flags.horizon-protection.overridable'));
    }
}
