<?php

namespace App\Providers;

use App\Policies\FeaturePolicy;
use App\Services\Features\FeatureResolver;
use Closure;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Events\FeatureDeleted;
use Laravel\Pennant\Events\FeatureUpdated;
use Laravel\Pennant\Events\FeatureUpdatedForAllScopes;
use Laravel\Pennant\Feature;

class FeatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(FeatureResolver::class);
    }

    public function boot(): void
    {
        Feature::resolveScopeUsing(fn ($driver) => null);

        foreach (config('features.flags') as $name => $flag) {
            Feature::define($name, $this->globalScopeOnlyDefault($flag['default']));
        }

        Gate::policy(Feature::class, FeaturePolicy::class);

        Event::listen(JobAttempted::class, fn () => Feature::flushCache());

        Event::listen(
            [FeatureUpdated::class, FeatureUpdatedForAllScopes::class, FeatureDeleted::class],
            fn () => $this->app->make(FeatureResolver::class)->flush()
        );
    }

    private function globalScopeOnlyDefault(bool $default): Closure
    {
        return fn (null $scope) => $default;
    }
}
