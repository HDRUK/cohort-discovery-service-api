<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

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

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            // Allow all in non-prod
            if (config('app.env') !== 'production') {
                return true;
            }

            // Allow in prod if ?key matches integrated.jwt_secret
            $key = (string) request()->query('key', '');
            $secret = (string) config('horizon.environments.'.config('app.env').'.secret', '');
            if ($secret !== '' && $key !== '' && hash_equals($secret, $key)) {
                return true;
            }

            //fallback - wont work now, but can implement in the future (maybe?)
            if (is_null($user)) {
                return false;
            }

            return $user->roles()->where('name', 'admin')->exists();
        });
    }
}
