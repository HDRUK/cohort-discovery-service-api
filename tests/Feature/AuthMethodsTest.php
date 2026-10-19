<?php

namespace Tests\Feature;

use Tests\Support\FakeIdp;
use Tests\TestCase;

class AuthMethodsTest extends TestCase
{
    private const FE_CALLBACK = 'http://fe.test/auth/sso/callback';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'system.operation_mode' => 'standalone',
            'auth.password_login.enabled' => true,
            'sso.enabled' => true,
            'sso.providers' => ['default' => FakeIdp::providerConfig()],
            'sso.frontend_callback_url' => self::FE_CALLBACK,
        ]);
    }

    private function methodTypes(): array
    {
        $response = $this->getJson('/api/auth/methods');
        $response->assertOk();

        return array_column($response->json('data'), 'type');
    }

    private function attemptLogin(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);
    }

    public function test_password_method_is_listed_when_enabled(): void
    {
        $this->assertContains('password', $this->methodTypes());

        $this->attemptLogin()->assertUnauthorized();
    }

    public function test_password_method_is_hidden_when_disabled(): void
    {
        config(['auth.password_login.enabled' => false]);

        $this->assertNotContains('password', $this->methodTypes());
    }

    public function test_login_is_rejected_when_password_login_is_disabled(): void
    {
        config(['auth.password_login.enabled' => false]);

        $this->attemptLogin()->assertForbidden();
    }

    public function test_login_is_not_blocked_in_integrated_mode(): void
    {
        config([
            'auth.password_login.enabled' => false,
            'system.operation_mode' => 'integrated',
        ]);

        $this->attemptLogin()->assertStatus(401);
    }

    public function test_password_login_survives_sso_being_disabled(): void
    {
        config([
            'auth.password_login.enabled' => false,
            'sso.enabled' => false,
        ]);

        $this->assertSame(['password'], $this->methodTypes());
        $this->attemptLogin()->assertUnauthorized();
    }

    public function test_password_login_survives_every_provider_being_disabled(): void
    {
        config([
            'auth.password_login.enabled' => false,
            'sso.providers' => ['default' => FakeIdp::providerConfig(['enabled' => false])],
        ]);

        $this->assertSame(['password'], $this->methodTypes());
        $this->attemptLogin()->assertUnauthorized();
    }

    public function test_password_login_survives_a_missing_frontend_callback_url(): void
    {
        config([
            'auth.password_login.enabled' => false,
            'sso.frontend_callback_url' => null,
        ]);

        $this->assertSame(['password'], $this->methodTypes());
        $this->attemptLogin()->assertUnauthorized();
    }

    public function test_only_enabled_providers_are_listed(): void
    {
        config([
            'sso.providers' => [
                'default' => FakeIdp::providerConfig(),
                'disabled_one' => FakeIdp::providerConfig(['enabled' => false]),
            ],
        ]);

        $response = $this->getJson('/api/auth/methods');

        $response->assertOk();
        $this->assertSame(
            [[
                'type' => 'oidc',
                'slug' => 'default',
                'label' => 'Fake IdP',
                'redirect_url' => url('/api/auth/sso/default/redirect'),
            ]],
            array_values(array_filter(
                $response->json('data'),
                fn (array $method) => $method['type'] === 'oidc'
            ))
        );
    }

    public function test_no_oidc_methods_are_listed_in_integrated_mode(): void
    {
        config(['system.operation_mode' => 'integrated']);

        $this->assertSame(['password'], $this->methodTypes());
    }
}
