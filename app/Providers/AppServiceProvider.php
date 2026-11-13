<?php

namespace App\Providers;

use App\Models\Collection;
use App\Models\Task;
use App\Models\User;
use App\Listeners\FeatureFlagUpdatedListener;
use App\Observers\CollectionObserver;
use App\Observers\TaskObserver;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Laravel\Pennant\Feature;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;

class AppServiceProvider extends ServiceProvider
{
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

        //use null scope by default
        Feature::resolveScopeUsing(fn ($driver) => null);

        // React to feature-flag changes (see FeatureFlagUpdatedListener for the switch).
        Event::listen(FeatureUpdatedForAllScopes::class, FeatureFlagUpdatedListener::class);

        RateLimiter::for('polling', function (Request $request) {
            return Limit::perMinute(config('api.rate_limit'))->by($request->ip());
        });

        RateLimiter::for('click-tracking', function (Request $request) {
            return Limit::perMinute(config('api.click_rate_limit'))
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
