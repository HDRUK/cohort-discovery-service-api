<?php

namespace App\Providers;

use App\Services\QueryContext\Contexts\Beacon\BeaconQueryContext;
use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use Illuminate\Support\ServiceProvider;

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
