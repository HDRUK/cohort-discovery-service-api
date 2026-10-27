<?php

namespace Tests\Feature;

use App\Enums\TaskType;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use App\Services\Collections\CollectionBacklogLimiter;
use DB;
use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class TaskPollBacklogGuardTest extends TestCase
{
    private const LIMIT = 2;

    private const SLACK_URL = 'https://hooks.slack.com/test';

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        DB::table('collection_activity_logs')->truncate();
        DB::table('model_states')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        config()->set('system.collection_pending_task_limit', self::LIMIT);
        config()->set('services.slack_webhook.url', self::SLACK_URL);
        Http::fake(['*' => Http::response([], 200)]);

        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $this->collection = Collection::factory()->bunny()->create();
        $this->collection->modelState()->create([
            'state_id' => $this->stateId(Collection::STATUS_SUSPENDED),
        ]);
    }

    private function stateId(string $slug): int
    {
        return State::query()->where('slug', $slug)->firstOrFail()->id;
    }

    private function fillBacklog(int $count, TaskType $taskType = TaskType::A): void
    {
        $user = User::factory()->create();
        $query = Query::factory()->create(['user_id' => $user->id]);

        Task::factory()->count($count)->create([
            'query_id' => $query->id,
            'collection_id' => $this->collection->id,
            'task_type' => $taskType,
            'completed_at' => null,
        ]);
    }

    private function poll(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/task/nextjob/'.$this->collection->pid.'.a');
    }

    private function currentState(): string
    {
        $this->collection->refresh()->load('modelState.state');

        return $this->collection->modelState->state->slug;
    }

    public function test_a_saturated_collection_stays_suspended_when_it_polls(): void
    {
        $this->fillBacklog(self::LIMIT, TaskType::B);

        $this->poll();

        $this->assertSame(Collection::STATUS_SUSPENDED, $this->currentState());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
    }

    public function test_a_saturated_collection_is_still_handed_work_to_drain(): void
    {
        $this->fillBacklog(self::LIMIT);

        $this->poll()->assertOk();

        $this->assertSame(Collection::STATUS_SUSPENDED, $this->currentState());
    }

    public function test_a_drained_collection_is_reactivated_when_it_polls(): void
    {
        $this->poll();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());
        Http::assertSent(
            fn ($request) => str_contains($request->url(), 'hooks.slack.com')
                && str_contains($request->body(), $this->collection->name)
        );
    }

    public function test_the_guard_does_not_apply_when_the_flag_is_off(): void
    {
        Feature::deactivate(CollectionBacklogLimiter::FEATURE);
        $this->fillBacklog(self::LIMIT, TaskType::B);

        $this->poll();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());
    }
}
