<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\ApplicationMode;
use App\Contracts\AuthenticationServiceInterface;
use App\Services\Authentication\IntegratedAuthenticationService;
use App\Services\Authentication\StandaloneAuthenticationService;

class ApplicationModeServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(AuthenticationServiceInterface::class, function () {
            if (ApplicationMode::isStandalone()) {
                return  app(StandaloneAuthenticationService::class);
            }

            return app(IntegratedAuthenticationService::class);
        });
    }
}
