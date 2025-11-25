<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

use App\Models\Task;
use App\Models\Query;
use App\Models\Collection;
use App\Models\CollectionConfig;
use App\Models\CollectionActivityLog;
use App\Enums\TaskType;
use App\Enums\CollectionStatus;
use App\Enums\FrequencyMode;
use App\Console\Commands\CollectionNoActivityMonitor;

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

        $collection = Collection::factory()->create([
            'name' => 'Activity_TestCollection',
            'status' => CollectionStatus::ACTIVE->value,
        ]);

        $config = CollectionConfig::create([
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

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'name' => 'Activity_TestCollection',
            'status' => CollectionStatus::SUSPENDED->value,
        ]);
    }

    public function test_it_doesnt_suspend_collections_with_activity_within_24_hours(): void
    {
        config()->set('system.collection_activity_log_type', 'log');

        $this->disableObservers();

        $now = CarbonImmutable::now($this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create([
            'name' => 'Activity_TestCollection',
        ]);

        $config = CollectionConfig::create([
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
            'created_at' => $now->subHour(2)->setTime(0, 0, 0),
            'updated_at' => $now->subHour(2)->setTime(0, 0, 0),
            'collection_id' => $collection->id,
            'task_type' => TaskType::A->value,
        ]);

        $this->assertDatabaseHas('collection_activity_logs', [
            'collection_id' => $collection->id,
            'task_type' => TaskType::A->value,
        ]);

        $command = new CollectionNoActivityMonitor();
        $result = $command->handle([]);

        $this->assertDatabaseHas('collections', [
            'id' => $collection->id,
            'name' => 'Activity_TestCollection',
            'status' => CollectionStatus::ACTIVE->value,
        ]);
    }
}
