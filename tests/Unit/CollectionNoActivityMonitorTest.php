<?php

use App\Console\Commands\CollectionNoActivityMonitor;
use App\Enums\FrequencyMode;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\Query;
use App\Models\Collection;
use App\Models\CollectionActivityLog;
use App\Models\CollectionConfig;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CollectionNoActivityMonitorTest extends TestCase
{
    private string $timezone = 'Europe/London';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        CollectionActivityLog::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_suspends_collections_after_24_hours_of_inactivity(): void
    {
        config()->set('system.collection_activity_log_type', 'log');

        $this->disableObservers();

        $now = CarbonImmutable::now($this->timezone);
        Carbon::setTestNow($now);

        $activeState = $this->getStateBySlugOrFail(Collection::STATUS_ACTIVE);
        $suspendedState = $this->getStateBySlugOrFail(Collection::STATUS_SUSPENDED);

        $collection = Collection::factory()->create([
            'name' => 'Activity_TestCollection',
        ]);

        $collection->modelState()->create([
            'state_id' => $activeState->id,
        ]);

        CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::MONTHLY->value,
            'run_time_frequency' => $now->weekOfMonth,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        $this->assertDatabaseHas('collection_config', [
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::MONTHLY->value,
            'run_time_frequency' => $now->weekOfMonth,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        CollectionActivityLog::create([
            'created_at' => $now->subDay(5)->setTime(0, 0, 0),
            'updated_at' => $now->subDay(5)->setTime(0, 0, 0),
            'collection_id' => $collection->id,
            'task_type' => TaskType::A->value,
        ]);

        $this->assertDatabaseHas('collection_activity_logs', [
            'collection_id' => $collection->id,
            'task_type' => TaskType::A->value,
        ]);

        $command = new CollectionNoActivityMonitor();
        $result = $command->handle([]);

        $this->assertSame(1, $result);

        $collection->refresh();
        $collection->load('modelState.state');

        $this->assertNotNull($collection->modelState);
        $this->assertSame($suspendedState->id, $collection->modelState->state_id);
        $this->assertSame(Collection::STATUS_SUSPENDED, $collection->modelState->state->slug);

        $this->assertDatabaseHas('model_states', [
            'state_id' => $suspendedState->id,
        ]);
    }

    public function test_it_suspends_collections_with_activity_older_than_60_minutes(): void
    {
        config()->set('system.collection_activity_log_type', 'log');
        config()->set('system.collection_inactivity_minutes', 60);

        $this->disableObservers();

        $now = CarbonImmutable::now($this->timezone);
        Carbon::setTestNow($now);

        $activeState = $this->getStateBySlugOrFail(Collection::STATUS_ACTIVE);
        $suspendedState = $this->getStateBySlugOrFail(Collection::STATUS_SUSPENDED);

        $collection = Collection::factory()->create([
            'name' => 'Activity_TestCollection',
        ]);

        $collection->modelState()->create([
            'state_id' => $activeState->id,
        ]);

        CollectionActivityLog::create([
            'created_at' => $now->subMinutes(61),
            'updated_at' => $now->subMinutes(61),
            'collection_id' => $collection->id,
            'task_type' => TaskType::A->value,
        ]);

        $command = new CollectionNoActivityMonitor();
        $command->handle([]);

        $collection->refresh();
        $collection->load('modelState.state');

        $this->assertSame($suspendedState->id, $collection->modelState->state_id);
    }

    private function getStateBySlugOrFail(string $slug): State
    {
        return State::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }
}
