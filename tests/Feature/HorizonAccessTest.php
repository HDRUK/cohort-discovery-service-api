<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ApplicationMode;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    private const COOKIE = 'token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'production',
            'horizon.token_cookie' => self::COOKIE,
            'horizon.open_outside_production' => false,
        ]);

        $this->enableMiddleware();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function withTokenCookie(string $token): static
    {
        return $this->withUnencryptedCookie(self::COOKIE, $token);
    }

    public function test_no_cookie_is_denied(): void
    {
        $this->get('/horizon')->assertForbidden();
    }

    public function test_a_valid_token_for_a_user_without_the_admin_role_is_denied(): void
    {
        $user = User::factory()->create();

        $this->withTokenCookie($this->makeJwtToken($user))->get('/horizon')->assertForbidden();
    }

    public function test_an_expired_admin_token_is_denied(): void
    {
        $token = $this->makeJwtToken($this->adminUser(), ['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->withTokenCookie($token)->get('/horizon')->assertForbidden();
    }

    public function test_a_tampered_admin_token_is_denied(): void
    {
        $token = $this->makeJwtToken($this->adminUser());
        $tampered = substr($token, 0, -6).'AAAAAA';

        $this->withTokenCookie($tampered)->get('/horizon')->assertForbidden();
    }

    public function test_a_valid_token_for_an_unknown_email_is_denied(): void
    {
        $this->adminUser();

        $this->withTokenCookie($this->makeJwtToken('nobody@example.com'))->get('/horizon')->assertForbidden();
    }

    public function test_a_token_in_the_query_string_is_not_read(): void
    {
        $token = $this->makeJwtToken($this->adminUser());

        $this->get('/horizon?token='.$token.'&key='.$token)->assertForbidden();
    }

    public function test_the_authorization_header_is_not_read(): void
    {
        $token = $this->makeJwtToken($this->adminUser());

        $this->withJwt($token)->get('/horizon')->assertForbidden();
    }

    public function test_the_old_shared_secret_as_a_basic_auth_password_is_denied(): void
    {
        config(['horizon.secret' => 'correct-horse-battery-staple']);

        $this->withBasicAuth('', 'correct-horse-battery-staple')->get('/horizon')->assertForbidden();
    }

    public function test_a_guest_is_denied_by_the_gate(): void
    {
        $this->assertFalse(Gate::forUser(null)->allows('viewHorizon'));
    }

    public function test_staging_is_denied_by_default(): void
    {
        config(['app.env' => 'staging']);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_staging_is_open_when_opted_in(): void
    {
        config(['app.env' => 'staging', 'horizon.open_outside_production' => true]);

        $this->get('/horizon')->assertOk();
    }

    public function test_the_opt_in_flag_does_not_open_production(): void
    {
        config(['horizon.open_outside_production' => true]);

        $this->get('/horizon')->assertForbidden();
    }

    public function test_an_admin_token_cookie_is_allowed_in_standalone_mode(): void
    {
        $token = $this->makeJwtToken($this->adminUser());

        $this->withTokenCookie($token)->get('/horizon')->assertOk();
    }

    public function test_an_admin_token_cookie_is_allowed_in_integrated_mode(): void
    {
        Config::set('system.operation_mode', ApplicationMode::INTEGRATED);
        Config::set('integrated.jwt_secret', Config::get('api.jwt_secret', 'test_secret'));

        $token = $this->makeJwtToken($this->adminUser());

        $this->withTokenCookie($token)->get('/horizon')->assertOk();
    }

    public function test_dashboard_api_calls_without_a_cookie_are_denied(): void
    {
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }
}
