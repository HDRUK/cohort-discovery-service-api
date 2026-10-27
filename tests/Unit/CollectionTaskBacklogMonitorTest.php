<?php

use App\Console\Commands\CollectionTaskBacklogMonitor;
use App\Models\Collection;
use App\Models\Query;
use App\Models\Task;
use App\Models\User;
use App\Services\Collections\CollectionBacklogLimiter;
use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class CollectionTaskBacklogMonitorTest extends TestCase
{
    private const LIMIT = 10;

    private const WARNING_LIMIT = 8;

    private const SLACK_URL = 'https://hooks.slack.com/test';

    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Task::truncate();
        Query::truncate();
        Collection::truncate();
        DB::table('model_states')->truncate();
        DB::table('activity_log')->truncate();
        DB::table('features')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        config()->set('system.collection_pending_task_limit', self::LIMIT);
        config()->set('system.collection_pending_task_warning_limit', self::WARNING_LIMIT);
        config()->set('services.slack_webhook.url', self::SLACK_URL);
        Http::fake(['*' => Http::response([], 200)]);

        Feature::activate(CollectionBacklogLimiter::FEATURE);

        $this->collection = Collection::factory()->create(['name' => 'Backlog_TestCollection']);
        $this->collection->modelState()->create([
            'state_id' => $this->stateId(Collection::STATUS_ACTIVE),
        ]);
    }

    private function stateId(string $slug): int
    {
        return State::query()->where('slug', $slug)->firstOrFail()->id;
    }

    private function fillBacklog(int $count): void
    {
        $user = User::factory()->create();
        $query = Query::factory()->create(['user_id' => $user->id]);

        Task::factory()->count($count)->create([
            'query_id' => $query->id,
            'collection_id' => $this->collection->id,
            'completed_at' => null,
        ]);
    }

    private function runMonitor(): mixed
    {
        return app(CollectionTaskBacklogMonitor::class)->handle([]);
    }

    private function currentState(): string
    {
        $this->collection->refresh()->load('modelState.state');

        return $this->collection->modelState->state->slug;
    }

    public function test_it_suspends_a_collection_at_the_limit(): void
    {
        $this->fillBacklog(self::LIMIT);

        $this->assertSame(1, $this->runMonitor());

        $this->assertSame(Collection::STATUS_SUSPENDED, $this->currentState());

        Http::assertSent(
            fn ($request) => str_contains($request->body(), $this->collection->name)
                && str_contains($request->body(), 'Suspended')
        );

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'collections',
            'description' => 'collection_task_backlog_suspended',
            'event' => 'task_backlog_suspended',
            'subject_id' => $this->collection->id,
            'causer_id' => null,
            'properties->pending_tasks' => self::LIMIT,
            'properties->limit' => self::LIMIT,
        ]);
    }

    public function test_it_warns_without_suspending_at_the_soft_threshold(): void
    {
        $this->fillBacklog(self::WARNING_LIMIT);

        $this->runMonitor();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());

        Http::assertSent(
            fn ($request) => str_contains($request->body(), $this->collection->name)
                && str_contains($request->body(), 'Backlog building')
        );
    }

    public function test_it_does_not_repeat_the_warning_within_the_throttle_window(): void
    {
        $this->fillBacklog(self::WARNING_LIMIT);

        $this->runMonitor();
        Http::fake(['*' => Http::response([], 200)]);
        $this->runMonitor();

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_below_both_thresholds(): void
    {
        $this->fillBacklog(self::WARNING_LIMIT - 1);

        $this->runMonitor();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());
        Http::assertNothingSent();
    }

    public function test_completed_tasks_do_not_count_towards_the_backlog(): void
    {
        $user = User::factory()->create();
        $query = Query::factory()->create(['user_id' => $user->id]);

        Task::factory()->count(self::LIMIT)->create([
            'query_id' => $query->id,
            'collection_id' => $this->collection->id,
            'completed_at' => now(),
        ]);

        $this->runMonitor();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());
        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_the_flag_is_off(): void
    {
        Feature::deactivate(CollectionBacklogLimiter::FEATURE);
        $this->fillBacklog(self::LIMIT);

        $this->runMonitor();

        $this->assertSame(Collection::STATUS_ACTIVE, $this->currentState());
        Http::assertNothingSent();
    }

    public function test_it_does_not_call_slack_when_webhook_is_not_configured(): void
    {
        config()->set('services.slack_webhook.url', null);
        $this->fillBacklog(self::LIMIT);

        $this->runMonitor();

        $this->assertSame(Collection::STATUS_SUSPENDED, $this->currentState());
        Http::assertNothingSent();
    }
}
