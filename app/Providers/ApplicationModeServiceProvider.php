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
        $this->app->bind(AuthenticationServiceInterface::class, function () {
            if (ApplicationMode::isStandalone()) {
                return app(StandaloneAuthenticationService::class);
            }

            return app(IntegratedAuthenticationService::class);
        });
    }
}
