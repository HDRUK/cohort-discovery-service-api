<?php

use App\Console\Commands\CollectionNoActivityMonitor;
use App\Console\Commands\Dispatchers\ApiCommandDispatcher;
use App\Console\Commands\DistributionsCollector;
use App\Jobs\TaskCleanupJob;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiCommandDispatcherTest extends TestCase
{
    private ApiCommandDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = app(ApiCommandDispatcher::class);
    }

    public function test_task_cleanup_job_dispatches_via_dispatcher(): void
    {
        Queue::fake();

        $this->dispatcher->run('task-cleanup-job', []);

        Queue::assertPushed(TaskCleanupJob::class);
    }

    public function test_distributions_collector_is_routed_via_dispatcher(): void
    {
        $mock = $this->mock(DistributionsCollector::class);
        $mock->shouldReceive('rules')->andReturn([]);
        $mock->shouldReceive('handle')->once()->with([])->andReturn([[], []]);

        $this->dispatcher->run('distributions-collector', []);
    }

    public function test_collection_no_activity_monitor_is_routed_via_dispatcher(): void
    {
        $mock = $this->mock(CollectionNoActivityMonitor::class);
        $mock->shouldReceive('rules')->andReturn([]);
        $mock->shouldReceive('handle')->once()->with([])->andReturn(1);

        $this->dispatcher->run('collection-no-activity-monitor', []);
    }

    public function test_unknown_command_throws_not_found_exception(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->dispatcher->run('non-existent-command', []);
    }
}
