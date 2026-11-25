<?php

namespace App\Providers;

use Closure;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class FeatureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn ($driver) => null);

        foreach (config('features.flags') as $name => $flag) {
            Feature::define($name, $this->globalScopeOnlyDefault($flag['default']));
        }

        Event::listen(JobAttempted::class, fn () => Feature::flushCache());
    }

    private function globalScopeOnlyDefault(bool $default): Closure
    {
        return fn (null $scope) => $default;
    }
}
