<?php

namespace Tests\Feature;

use App\Jobs\TaskCleanupJob;
use App\Models\CollectionHost;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServiceCallerAuthTest extends TestCase
{
    private string $url = '/api/v1/services/caller/task-cleanup-job';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();
        config(['system.basic_auth_enabled' => true]);
    }

    public function test_service_caller_rejects_unauthenticated_requests(): void
    {
        $response = $this->postJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_service_caller_rejects_bad_credentials(): void
    {
        $header = 'Basic '.base64_encode('unknown-client:wrong-secret');

        $response = $this->withHeader('Authorization', $header)->postJson($this->url);

        $response->assertUnauthorized();
    }

    public function test_service_caller_accepts_collection_host_credentials(): void
    {
        Queue::fake();

        CollectionHost::factory()->create([
            'client_id' => 'internal-scheduler',
            'client_secret' => 'scheduler-secret',
        ]);

        $header = 'Basic '.base64_encode('internal-scheduler:scheduler-secret');

        $response = $this->withHeader('Authorization', $header)->postJson($this->url);

        $response->assertOk();
        Queue::assertPushed(TaskCleanupJob::class);
    }
}
