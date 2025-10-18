<?php

namespace Tests\Unit;

use Config;
use Illuminate\Support\Facades\Route;
use Hdruk\LaravelPluginCore\Services\PluginManager;
use Tests\TestCase;
use Mockery;

class PluginMiddlewareIntegrationTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->disablePlugin(false);
    }

    public function tearDown(): void
    {
        $this->disablePlugin(true);

        Mockery::close();
        parent::tearDown();
    }

    public function test_plugin_middleware_modifies_response()
    {
        // Because we've toggled the state of the plugin, we
        // need to do this hefty call. Yikes!
        $this->refreshApplication();

        // Force a reload once the application has finished
        // booting.
        $this->app->instance(PluginManager::class, new PluginManager(config('plugin-core.path')));
        $this->app->register(\Hdruk\LaravelPluginCore\PluginCoreServiceProvider::class);

        // Call the route registered within the plugin.
        $response = $this->getJson('api/v1/test-plugin-endpoint');

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'plugin' => true,
        ]);
    }

    protected function disablePlugin(bool $toggle): void
    {
        $pluginPath = Config::get('plugin-core.path') . '/TestPlugin/plugin.json';
        $pluginData = json_decode(file_get_contents($pluginPath), true);
        $pluginData['disabled'] = $toggle;
        file_put_contents($pluginPath, json_encode($pluginData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
