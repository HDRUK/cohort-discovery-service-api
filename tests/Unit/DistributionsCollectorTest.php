<?php

namespace Tests\Unit;

use Tests\TestCase;
use DB;
use Carbon\Carbon;
use Illuminate\SUpport\Facades\Log;
use App\Models\Task;
use App\Models\Query;
use App\Models\Collection;
use App\Models\CollectionConfig;
use App\Models\CollectionConfigRun;
use App\Console\Commands\DistributionsCollector;
use App\Enums\TaskType;
use App\Enums\QueryType;
use App\Enums\FrequencyMode;

class DistributionsCollectorTest extends TestCase
{
    private string $timezone = 'Europe/London';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Collection::truncate();
        CollectionConfig::truncate();
        CollectionConfigRun::truncate();
        Task::truncate();
        Query::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_creates_query_and_task_for_monthly_config_matching_time_and_week(): void
    {
        $this->disableObservers();

        $now = Carbon::now($this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create([
            'name' => 'TestCollection',
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

        Log::spy();

        $command = new DistributionsCollector();
        $result = $command->handle([]);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector starting:')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'running per monthly schedule')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector generating queries and tasks for')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'created Task')
        );

        $this->assertDatabaseCount('queries', 1);
        $this->assertDatabaseCount('tasks', 1);

        $this->assertTrue($result[0]['monthly'] === 1);
        $this->assertTrue(json_decode($result[1]['configs'][0], true)['id'] === $collection->id);

        $query = Query::first();
        $task = Task::first();

        $this->assertTrue(QueryType::GENERIC->value === $query->definition['code']);
        $this->assertEquals($collection->id, $task->collection_id);
        $this->assertEquals($query->id, $task->query_id);
        $this->assertTrue(TaskType::A->value === $task->task_type->value);
        $this->assertEquals($query->name, 'omop-concept-job-' . strtolower($collection->name));
    }

    public function test_it_creates_query_and_task_for_weekly_config_matching_time_and_day(): void
    {
        $this->disableObservers();

        $now = Carbon::now($this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create([
            'name' => 'TestCollection',
        ]);

        $config = CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::WEEKLY->value,
            'run_time_frequency' => $now->dayOfWeek,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        $this->assertDatabaseHas('collection_config', [
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::WEEKLY->value,
            'run_time_frequency' => $now->dayOfWeek,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        Log::spy();

        $command = new DistributionsCollector();
        $result = $command->handle([]);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector starting:')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'running per weekly schedule')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector generating queries and tasks for')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'created Task')
        );

        $this->assertDatabaseCount('queries', 1);
        $this->assertDatabaseCount('tasks', 1);

        $this->assertTrue($result[0]['weekly'] === 1);
        $this->assertTrue(json_decode($result[1]['configs'][0], true)['id'] === $collection->id);

        $query = Query::first();
        $task = Task::first();

        $this->assertTrue(QueryType::GENERIC->value === $query->definition['code']);
        $this->assertEquals($collection->id, $task->collection_id);
        $this->assertEquals($query->id, $task->query_id);
        $this->assertTrue(TaskType::A->value === $task->task_type->value);
        $this->assertEquals($query->name, 'omop-concept-job-' . strtolower($collection->name));
    }
}
