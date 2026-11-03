<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Jobs\TaskCleanupJob;
use App\Models\Task;
use App\Models\TaskRun;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaskCleanupJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00'));
        config([
            'tasks.default_timeout_seconds' => 300,
            'tasks.b_type_timeout_seconds' => 3600,
        ]);
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_fails_an_a_type_task_older_than_the_default_timeout(): void
    {
        $task = $this->makeTask(TaskType::A, now()->subMinutes(6));

        (new TaskCleanupJob())->handle();

        $task->refresh();
        $this->assertNotNull($task->failed_at);
        $this->assertNotNull($task->completed_at);

        $run = TaskRun::where('task_id', $task->id)->first();
        $this->assertSame('Timeout', $run->error_class);
        $this->assertSame('No result received within 5 minutes (A-type task).', $run->error_message);
    }

    #[Test]
    public function it_does_not_fail_a_b_type_task_within_the_b_type_timeout(): void
    {
        $task = $this->makeTask(TaskType::B, now()->subMinutes(10));

        (new TaskCleanupJob())->handle();

        $task->refresh();
        $this->assertNull($task->failed_at);
        $this->assertNull($task->completed_at);
        $this->assertSame(0, TaskRun::where('task_id', $task->id)->count());
    }

    #[Test]
    public function it_fails_a_b_type_task_older_than_the_b_type_timeout(): void
    {
        $task = $this->makeTask(TaskType::B, now()->subMinutes(61));

        (new TaskCleanupJob())->handle();

        $task->refresh();
        $this->assertNotNull($task->failed_at);

        $run = TaskRun::where('task_id', $task->id)->first();
        $this->assertSame('Timeout', $run->error_class);
        $this->assertSame('No result received within 1 hour (B-type task).', $run->error_message);
    }

    #[Test]
    public function it_keeps_processing_the_chunk_after_a_leased_task(): void
    {
        $leased = $this->makeTask(TaskType::A, now()->subMinutes(10), [
            'leased_until' => now()->addMinute(),
        ]);
        $expired = $this->makeTask(TaskType::A, now()->subMinutes(10));

        (new TaskCleanupJob())->handle();

        $this->assertNull($leased->refresh()->failed_at);
        $this->assertNotNull($expired->refresh()->failed_at);
    }

    private function makeTask(TaskType $taskType, Carbon $createdAt, array $attributes = []): Task
    {
        return Task::factory()->create([
            'task_type' => $taskType,
            'created_at' => $createdAt,
            ...$attributes,
        ]);
    }
}
