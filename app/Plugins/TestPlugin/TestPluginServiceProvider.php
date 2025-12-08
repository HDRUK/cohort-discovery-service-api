<?php

namespace App\Plugins\TestPlugin;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TestPluginServiceProvider extends ServiceProvider
{
    public function register()
    {
        // no bindings needed for this test plugin
        //\Log::info('TestPluginServiceProvider - registered');
    }

    public function boot()
    {
        // optionally register routes if your plugin has its own routes
        // for this test, we’re just using middleware
        Route::middleware('inject.plugins')->get(
            'api/v1/test-plugin-endpoint',
            fn () => response()->json(['success' => true], 200)
        );

        //\Log::info('TestPluginServiceProvider - booted');
    }
}
