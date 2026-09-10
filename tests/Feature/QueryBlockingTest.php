<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class QueryBlockingTest extends TestCase
{
    private const BASE_URL = '/api/v1/queries';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'database');

        // Observers stay disabled (the default): the submission flow sets every
        // pid explicitly, and CollectionObserver is irrelevant here.
        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        Result::truncate();
        DB::table('task_runs')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
    }

    public function test_location_query_fails_only_collections_missing_location(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        $enabled = $this->makeCollection(['location_enabled' => true]);
        $disabled = $this->makeCollection(['location_enabled' => false]);

        $this->submit($this->leaf('Location'), [$enabled, $disabled]);

        $enabledTask = Task::where('collection_id', $enabled->id)->first();
        $disabledTask = Task::where('collection_id', $disabled->id)->first();

        $this->assertNull($enabledTask->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $enabledTask->id]);

        $this->assertNotNull($disabledTask->failed_at);
        $this->assertNotNull($disabledTask->completed_at);
        $this->assertSame(
            'Location data not yet available',
            Result::where('task_id', $disabledTask->id)->value('message')
        );
    }

    public function test_death_feature_off_fails_all_collections_regardless_of_flag(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::deactivate('query-builder-use-death');

        // Both collections declare they HAVE the death table...
        $one = $this->makeCollection(['death_enabled' => true]);
        $two = $this->makeCollection(['death_enabled' => true]);

        $this->submit($this->leaf('Death'), [$one, $two]);

        // ...but the global feature switch is off, so every task is blocked.
        foreach ([$one, $two] as $collection) {
            $task = Task::where('collection_id', $collection->id)->first();
            $this->assertNotNull($task->failed_at);
            $this->assertSame(
                'Death-record data not yet available',
                Result::where('task_id', $task->id)->value('message')
            );
        }
    }

    public function test_query_using_both_tables_records_both_reasons(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        $collection = $this->makeCollection([
            'location_enabled' => false,
            'death_enabled' => false,
        ]);

        $this->submit(
            ['rules' => [$this->leafNode('Location'), $this->leafNode('Death')]],
            [$collection]
        );

        $task = Task::where('collection_id', $collection->id)->first();

        $this->assertNotNull($task->failed_at);
        $this->assertSame(
            'Location data not yet available; Death-record data not yet available',
            Result::where('task_id', $task->id)->value('message')
        );
    }

    public function test_demographic_location_query_fails_collection_missing_location(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        $enabled = $this->makeCollection(['location_enabled' => true]);
        $disabled = $this->makeCollection(['location_enabled' => false]);

        // No clinical Location concept - the location lives only in the
        // demographics block as a geo-radius filter.
        $definition = [
            'rules' => [$this->leafNode('Drug')],
            'demographics' => ['location' => ['lat' => 51.5, 'lon' => -0.12, 'radius' => 5000]],
        ];

        $this->submit($definition, [$enabled, $disabled]);

        $enabledTask = Task::where('collection_id', $enabled->id)->first();
        $disabledTask = Task::where('collection_id', $disabled->id)->first();

        $this->assertNull($enabledTask->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $enabledTask->id]);

        $this->assertNotNull($disabledTask->failed_at);
        $this->assertSame(
            'Location data not yet available',
            Result::where('task_id', $disabledTask->id)->value('message')
        );
    }

    public function test_demographic_death_query_fails_collection_missing_death(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        $enabled = $this->makeCollection(['death_enabled' => true]);
        $disabled = $this->makeCollection(['death_enabled' => false]);

        // No clinical Death concept - the death filter lives only in the
        // demographics block.
        $definition = [
            'rules' => [$this->leafNode('Drug')],
            'demographics' => ['death' => ['label' => 'Recorded', 'value' => 1]],
        ];

        $this->submit($definition, [$enabled, $disabled]);

        $enabledTask = Task::where('collection_id', $enabled->id)->first();
        $disabledTask = Task::where('collection_id', $disabled->id)->first();

        $this->assertNull($enabledTask->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $enabledTask->id]);

        $this->assertNotNull($disabledTask->failed_at);
        $this->assertNotNull($disabledTask->completed_at);
        $this->assertSame(
            'Death-record data not yet available',
            Result::where('task_id', $disabledTask->id)->value('message')
        );
    }

    public function test_demographic_death_not_recorded_also_requires_death_table(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        // A value of 0 still emits a rule against the death table, so it still
        // requires the collection to expose it.
        $collection = $this->makeCollection(['death_enabled' => false]);

        $definition = [
            'rules' => [$this->leafNode('Drug')],
            'demographics' => ['death' => ['label' => 'Not recorded', 'value' => 0]],
        ];

        $this->submit($definition, [$collection]);

        $task = Task::where('collection_id', $collection->id)->first();

        $this->assertNotNull($task->failed_at);
        $this->assertSame(
            'Death-record data not yet available',
            Result::where('task_id', $task->id)->value('message')
        );
    }

    public function test_null_demographic_death_is_not_blocked(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        // An untouched death filter emits no rule, so it must not block.
        $collection = $this->makeCollection(['death_enabled' => false]);

        $definition = [
            'rules' => [$this->leafNode('Drug')],
            'demographics' => ['death' => null],
        ];

        $this->submit($definition, [$collection]);

        $task = Task::where('collection_id', $collection->id)->first();

        $this->assertNull($task->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $task->id]);
    }

    public function test_legacy_region_code_location_shape_is_not_blocked(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        // Legacy region-code array shape emits no GEO_RADIUS rule, so it does
        // not require the location table and must not be blocked.
        $collection = $this->makeCollection(['location_enabled' => false]);

        $definition = [
            'rules' => [$this->leafNode('Drug')],
            'demographics' => ['location' => ['E12000007', 'E12000008']],
        ];

        $this->submit($definition, [$collection]);

        $task = Task::where('collection_id', $collection->id)->first();

        $this->assertNull($task->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $task->id]);
    }

    public function test_query_without_location_or_death_is_not_blocked(): void
    {
        Feature::activate('query-builder-use-location');
        Feature::activate('query-builder-use-death');

        // Flags off, but the query never touches those tables.
        $collection = $this->makeCollection([
            'location_enabled' => false,
            'death_enabled' => false,
        ]);

        $this->submit($this->leaf('Drug'), [$collection]);

        $task = Task::where('collection_id', $collection->id)->first();

        $this->assertNull($task->failed_at);
        $this->assertDatabaseMissing('results', ['task_id' => $task->id]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, Collection>  $collections
     */
    private function makeCollection(array $attrs): Collection
    {
        return Collection::factory()->bunny()->create($attrs);
    }

    private function submit(array $definition, array $collections): void
    {
        $response = $this->actingAsJwt($this->user)
            ->postJson(self::BASE_URL, [
                'name' => 'Blocking test',
                'definition' => $definition,
                'collection_filter' => collect($collections)->pluck('pid')->toArray(),
                'task_type' => TaskType::A,
            ]);

        $response->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function leaf(string $category): array
    {
        return ['rules' => [$this->leafNode($category)]];
    }

    /**
     * @return array<string, mixed>
     */
    private function leafNode(string $category): array
    {
        return ['rule' => ['concept' => ['concept_id' => 1, 'category' => $category]]];
    }
}
