<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\QueryContext\Contexts\{
    Bunny\BunnyQueryContext,
    Beacon\BeaconQueryContext
};

class QueryContextServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->tag([
            BunnyQueryContext::class,
            BeaconQueryContext::class,
        ], 'query_contexts');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
