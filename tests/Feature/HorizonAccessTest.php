<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    private const SECRET = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'production',
            'horizon.secret' => self::SECRET,
        ]);

        $this->enableMiddleware();
    }

    public function test_no_credentials_are_challenged_for_basic_auth(): void
    {
        $this->get('/horizon')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Horizon"');
    }

    public function test_the_secret_in_the_query_string_no_longer_grants_access(): void
    {
        $this->get('/horizon?key='.self::SECRET)->assertUnauthorized();
    }

    public function test_a_wrong_password_is_challenged(): void
    {
        $this->withBasicAuth('', 'wrong')->get('/horizon')->assertUnauthorized();
    }

    public function test_an_unset_secret_denies_an_empty_password(): void
    {
        config(['horizon.secret' => '']);

        $this->withBasicAuth('', '')->get('/horizon')->assertUnauthorized();
    }

    public function test_the_secret_as_the_basic_auth_password_is_allowed(): void
    {
        $this->withBasicAuth('', self::SECRET)->get('/horizon')->assertOk();
    }

    public function test_dashboard_api_calls_are_allowed_with_the_same_credentials(): void
    {
        $dashboardApiCall = Request::create('/horizon/api/stats', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode(':'.self::SECRET),
        ]);
        app()->instance('request', $dashboardApiCall);

        $this->assertTrue(Gate::forUser(null)->allows('viewHorizon'));
    }

    public function test_outside_production_no_credentials_are_needed(): void
    {
        config(['app.env' => 'staging']);

        $this->get('/horizon')->assertOk();
    }
}
