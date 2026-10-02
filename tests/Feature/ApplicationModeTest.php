<?php

namespace Tests\Feature;

use App\Providers\ApplicationModeServiceProvider;
use App\Support\ApplicationMode;
use Tests\TestCase;

class ApplicationModeTest extends TestCase
{
    public function test_sso_routes_404_in_integrated_mode(): void
    {
        config([
            'system.operation_mode' => ApplicationMode::INTEGRATED,
            'sso.enabled' => true,
        ]);

        // SSO is standalone-only: in integrated mode the Gateway is the IdP,
        // and there is no second front door
        $this->getJson('/api/auth/sso/providers')->assertNotFound();
        $this->get('/api/auth/sso/default/redirect')->assertNotFound();
        $this->get('/api/auth/sso/default/callback')->assertNotFound();
        $this->postJson('/api/auth/sso/exchange', ['code' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_sso_routes_404_when_sso_is_disabled_in_standalone(): void
    {
        config([
            'system.operation_mode' => ApplicationMode::STANDALONE,
            'sso.enabled' => false,
        ]);

        $this->getJson('/api/auth/sso/providers')->assertNotFound();
    }

    public function test_an_unrecognised_operation_mode_refuses_to_boot(): void
    {
        config(['system.operation_mode' => 'intergrated']); // the classic typo

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_OPERATION_MODE is [intergrated]');

        (new ApplicationModeServiceProvider($this->app))->register();
    }

    public function test_both_valid_modes_boot(): void
    {
        foreach (ApplicationMode::MODES as $mode) {
            config(['system.operation_mode' => $mode]);

            (new ApplicationModeServiceProvider($this->app))->register();
        }

        $this->assertTrue(true, 'both documented modes register without throwing');
    }
}
