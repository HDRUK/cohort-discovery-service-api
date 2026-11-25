<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateHorizonServiceAccount;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Pennant\Feature;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    protected function authorization(): void
    {
        Horizon::auth(fn (Request $request) => $this->isAuthorised($request));
    }

    protected function gate(): void
    {
    }

    private function isAuthorised(Request $request): bool
    {
        return $request->attributes->get(AuthenticateHorizonServiceAccount::AUTHENTICATED) === true
            || app()->environment('local')
            || ! Feature::active(AuthenticateHorizonServiceAccount::PROTECTION_FEATURE);
    }
}
