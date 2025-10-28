<?php

namespace App\Providers;

use Carbon\CarbonInterval;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;
use Hdruk\ClaimsAccessControl\Services\ClaimMappingService;
use Hdruk\ClaimsAccessControl\Services\ClaimResolverService;
use App\Models\User;
use App\Models\Task;
use App\Models\Collection;
use App\Observers\CollectionObserver;
use App\Observers\TaskObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClaimMappingService::class, function () {
            return (new ClaimMappingService())
                ->setMap(config('claimsaccesscontrol.workgroup_mappings'));
        });

        $this->app->singleton(ClaimResolverService::class, function ($app) {
            return new ClaimResolverService(
                $app->make(ClaimMappingService::class)
            );
        });
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

        Collection::observe(CollectionObserver::class);
        Task::observe(TaskObserver::class);

        RateLimiter::for('polling', function (Request $request) {
            return Limit::perMinute(config('api.rate_limit'))->by($request->ip());
        });
    }
}
