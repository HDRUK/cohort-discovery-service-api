<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use App\Services\QueryContext\Contexts\Beacon\BeaconQueryContext;
use App\Services\QueryContext\QueryContextManager;
use App\Services\QueryContext\QueryContextType;

class QueryContextTest extends TestCase
{
    /**
     * A basic unit test example.
     */
    public function test_application_has_registered_query_contexts(): void
    {
        $contexts = $this->app->tagged('query_contexts');
        $this->assertNotEmpty($contexts, 'No query contexts registered in the application.');

        foreach ($contexts as $context) {
            $this->assertInstanceOf(
                QueryContextInterface::class,
                $context,
                'Context is not an instance of QueryContextInterface: ' . get_class($context)
            );
        }
    }

    public function test_application_can_translate_bunny_query(): void
    {
        $bunnyContext = $this->app->make(BunnyQueryContext::class);
        $jsonQuery = '{"query": "SELECT * FROM little_fluffy_bunnies"}';

        $result = $bunnyContext->translate($jsonQuery);

        $this->assertIsArray($result, 'Bunny query translation did not return an array.');
        $this->assertEquals(json_decode($jsonQuery, true), $result, 'Bunny query translation did not match expected output.');
    }

    public function test_application_can_translate_beacon_query(): void
    {
        $beaconContext = $this->app->make(BeaconQueryContext::class);
        $jsonQuery = '{"query": "SELECT * FROM ga4gh_beacon_v2_things"}';

        $result = $beaconContext->translate($jsonQuery);

        $this->assertIsArray($result, 'Beacon query translation did not return an array.');
        $this->assertEquals(json_decode($jsonQuery, true), $result, 'Beacon query translation did not match expected output.');
    }

    public function test_application_can_translate_via_manager(): void
    {
        $manager = $this->app->make(QueryContextManager::class);

        $jsonQuery = '{"query": "SELECT * FROM little_fluffy_bunnies"}';

        $result = $manager->handle($jsonQuery, QueryContextType::Bunny);
        $this->assertIsArray($result, 'Bunny query translation via manager did not return an array.');
        $this->assertEquals(json_decode($jsonQuery, true), $result, 'Bunny query via manager did not match expected output.');

        $jsonQuery = '{"query": "SELECT * FROM ga4gh_beacon_v2_things"}';

        $result = $manager->handle($jsonQuery, QueryContextType::Beacon);
        $this->assertIsArray($result, 'Beacon query translation via manager did not return an array.');
        $this->assertEquals(json_decode($jsonQuery, true), $result, 'Beacon query via manager did not match expected output.');

    }
}
