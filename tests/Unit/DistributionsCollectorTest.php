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

        config(['app.timezone' => 'Europe/London']);
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

    public function test_it_creates_query_and_task_for_quarterly_config_matching_time_and_quarter(): void
    {
        $this->disableObservers();

        // Pick a date inside Q2 (April 10 example)
        $now = Carbon::now($this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create([
            'name' => 'QuarterlyCollection',
        ]);

        $currentQuarter = ceil($now->month / 3);

        $config = CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::QUARTERLY->value,
            'run_time_frequency' => (int)$currentQuarter,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        $this->assertDatabaseHas('collection_config', [
            'run_time_frequency' => $currentQuarter,
            'frequency_mode' => FrequencyMode::QUARTERLY->value,
        ]);

        Log::spy();

        $command = new DistributionsCollector();
        $result = $command->handle([]);

        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector starting:')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'running per quarterly schedule')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector generating queries and tasks for')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'created Task')
        );

        $this->assertDatabaseCount('queries', 1);
        $this->assertDatabaseCount('tasks', 1);

        $this->assertEquals(1, $result[0]['quarterly']);
        $this->assertEquals($collection->id, json_decode($result[1]['configs'][0], true)['id']);
    }

    public function test_it_creates_query_and_task_for_biannual_config_matching_time_and_half(): void
    {
        $this->disableObservers();

        $now = Carbon::create(2025, 2, 8, 9, 0, 0, $this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create(['name' => 'BiannualCollection']);
        $currentHalf = ($now->month <= 6) ? 1 : 2;

        $config = CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::BIANNUALLY->value,
            'run_time_frequency' => $currentHalf,
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
            fn ($message) => str_contains($message, 'running per biannual schedule')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'DistributionsCollector generating queries and tasks for')
        );
        Log::shouldHaveReceived('info')->withArgs(
            fn ($message) => str_contains($message, 'created Task')
        );

        $this->assertDatabaseCount('queries', 1);
        $this->assertDatabaseCount('tasks', 1);

        $this->assertEquals(1, $result[0]['biannually']);
        $this->assertEquals($collection->id, json_decode($result[1]['configs'][0], true)['id']);
    }


    public function test_quarterly_config_does_not_run_if_already_ran_this_quarter(): void
    {
        $this->disableObservers();

        $now = Carbon::create(2025, 8, 15, 10, 15, 0, $this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create(['name' => 'QuarterlyCollection']);
        $quarter = ceil($now->month / 3);

        $config = CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::QUARTERLY->value,
            'run_time_frequency' => $quarter,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        // Already ran this quarter
        CollectionConfigRun::create([
            'collection_config_id' => $config->id,
            'ran_at' => Carbon::create(2025, 7, 5, 10, 0, 0, $this->timezone),
            'successful' => true
        ]);

        Log::spy();

        $command = new DistributionsCollector();
        $result = $command->handle([]);

        Log::shouldHaveReceived('info')->withArgs(
            fn($msg) => str_contains($msg, 'already ran this quarter')
        );

        $this->assertDatabaseCount('queries', 0);
        $this->assertDatabaseCount('tasks', 0);

        $this->assertEquals($result[0]['quarterly'], 0);
    }

    public function test_biannual_config_does_not_run_if_already_ran_this_half(): void
    {
        $this->disableObservers();

        $now = Carbon::create(2025, 10, 3, 13, 20, 0, $this->timezone);
        Carbon::setTestNow($now);

        $collection = Collection::factory()->create(['name' => 'BiannualCollection']);
        $currentHalf = ($now->month <= 6) ? 1 : 2;

        $config = CollectionConfig::create([
            'enabled' => 1,
            'run_time_hour' => $now->hour,
            'run_time_minute' => $now->minute,
            'frequency_mode' => FrequencyMode::BIANNUALLY->value,
            'run_time_frequency' => $currentHalf,
            'collection_id' => $collection->id,
            'type' => TaskType::A->value,
        ]);

        CollectionConfigRun::create([
            'collection_config_id' => $config->id,
            'ran_at' => Carbon::create(2025, 8, 1, 10, 0, 0, $this->timezone),
            'successful' => true
        ]);

        Log::spy();

        $command = new DistributionsCollector();
        $result = $command->handle([]);

        Log::shouldHaveReceived('info')->withArgs(
            fn($msg) => str_contains($msg, 'already ran this half of the year')
        );

        $this->assertDatabaseCount('queries', 0);
        $this->assertDatabaseCount('tasks', 0);

        $this->assertEquals($result[0]['biannually'], 0);
    }
}
