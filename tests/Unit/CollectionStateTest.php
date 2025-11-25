<?php

namespace Tests\Unit;

use DB;
use Tests\TestCase;
use Hdruk\LaravelModelStates\Models\State;
use Hdruk\LaravelModelStates\Models\ModelState;
use App\Models\Custodian;
use App\Models\Collection;

class CollectionStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        ModelState::truncate();
        State::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Seed some states
        foreach ([
            Collection::STATUS_DRAFT,
            Collection::STATUS_PENDING,
            Collection::STATUS_ACTIVE,
            Collection::STATUS_REJECTED,
            Collection::STATUS_SUSPENDED,
        ] as $slug) {
            State::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('_', ' ', $slug))]
            );
        }
    }

    public function test_it_sets_default_state_on_creation()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->disableObservers();

        $this->assertEquals(Collection::STATUS_DRAFT, $collection->getState());
        $this->assertDatabaseHas('model_states', [
            'stateable_type' => Collection::class,
            'stateable_id'   => $collection->id,
            'state_id'       => State::where('slug', Collection::STATUS_DRAFT)->first()->id,
        ]);
    }

    public function test_it_allows_a_valid_transition()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->disableObservers();

        $collection->transitionTo(Collection::STATUS_PENDING);

        $this->assertEquals(Collection::STATUS_PENDING, $collection->getState());
    }

    public function test_it_blocks_an_invalid_transition()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->disableObservers();

        $collection->transitionTo(Collection::STATUS_ACTIVE);

        $this->expectException(\Exception::class);
        $collection->transitionTo(Collection::STATUS_REJECTED);
    }

    public function test_can_transition_to_reports_correct_values()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->assertTrue($collection->canTransitionTo(Collection::STATUS_PENDING));
        $this->assertTrue($collection->canTransitionTo(Collection::STATUS_ACTIVE));

        $collection->transitionTo(Collection::STATUS_ACTIVE);

        $this->assertFalse($collection->canTransitionTo(Collection::STATUS_REJECTED));
        $this->assertTrue($collection->canTransitionTo(Collection::STATUS_SUSPENDED));
    }

    public function test_state_relationship_refreshes_correctly()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->disableObservers();

        $collection->transitionTo(Collection::STATUS_PENDING);

        $this->assertEquals(
            Collection::STATUS_PENDING,
            $collection->modelState->state->slug
        );
    }

    public function test_model_state_record_updates_on_transition()
    {
        $this->enableObservers();

        $custodian = Custodian::factory()->create();
        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);

        $this->disableObservers();

        $collection->transitionTo(Collection::STATUS_ACTIVE);

        $this->assertDatabaseHas('model_states', [
            'stateable_type' => Collection::class,
            'stateable_id'   => $collection->id,
            'state_id'       => State::where('slug', Collection::STATUS_ACTIVE)->first()->id,
        ]);
    }
}
