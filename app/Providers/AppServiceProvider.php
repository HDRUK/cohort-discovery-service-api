<?php

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Support\ServiceProvider;

use App\Models\User;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Configure what token scopes are available to request.
         * 
         * Contains a few 'demo' scopes to test oauth2, client
         * creation and code requests.
         */
        Passport::tokensCan(User::CLIENT_TOKEN_SCOPES);

        Passport::tokensExpireIn(CarbonInterval::days(config('passport.token_expire')));
        Passport::refreshTokensExpireIn(CarbonInterval::days(config('passport.refresh_expire')));
        Passport::personalAccessTokensExpireIn(CarbonInterval::months(config('passport.access_expire')));
    }
}
