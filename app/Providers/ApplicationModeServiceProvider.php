<?php

namespace App\Providers;

use App\Contracts\AuthenticationServiceInterface;
use App\Services\Authentication\IntegratedAuthenticationService;
use App\Services\Authentication\StandaloneAuthenticationService;
use App\Support\ApplicationMode;
use Illuminate\Support\ServiceProvider;

class ApplicationModeServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->assertOperationModeIsValid();

        $this->app->bind(AuthenticationServiceInterface::class, function () {
            if (ApplicationMode::isStandalone()) {
                return app(StandaloneAuthenticationService::class);
            }

            return app(IntegratedAuthenticationService::class);
        });
    }

    /**
     * Refuse to boot on an unrecognised APP_OPERATION_MODE.
     *
     * Both isStandalone() and isIntegrated() return false for an unknown
     * value, and the binding above treats "not standalone" as integrated - so
     * a typo would quietly hand every request to the Gateway auth path and
     * 404 the SSO routes, with nothing anywhere to say why.
     */
    private function assertOperationModeIsValid(): void
    {
        $mode = config('system.operation_mode');

        if (in_array($mode, ApplicationMode::MODES, true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'APP_OPERATION_MODE is [%s]; expected one of: %s. '
            .'Use "standalone" unless this deployment is the HDR UK Gateway integration.',
            is_scalar($mode) ? (string) $mode : gettype($mode),
            implode(', ', ApplicationMode::MODES)
        ));
    }
}
