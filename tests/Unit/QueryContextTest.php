<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\QueryContext\Contexts\QueryContextInterface;
use App\Services\QueryContext\Contexts\Bunny\BunnyQueryContext;
use App\Services\QueryContext\Contexts\Beacon\BeaconQueryContext;

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

    public function test_correct_context_types_are_detected(): void
    {
        $contexts = iterator_to_array($this->app->tagged('query_contexts'));
        $types = array_map(fn ($c) => $c->getType()->value, $contexts);

        foreach ($contexts as $context) {
            $this->assertContains($context->getType()->value, $types);
        }
    }
}
