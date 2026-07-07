<?php

namespace Tests\Unit;

use App\Models\Collection;
use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Services\Collections\CollectionStateService;
use DB;
use Hdruk\LaravelModelStates\Models\ModelState;
use Hdruk\LaravelModelStates\Models\State;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CollectionStateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        User::truncate();
        Custodian::truncate();
        CustodianHasUser::truncate();
        Collection::truncate();
        ModelState::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function test_it_sends_slack_message_on_draft_to_pending_transition(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config()->set('services.slack_webhook.url', 'https://hooks.slack.com/test');

        [$user, $collection] = $this->makeCustodianUserAndCollection();

        $service = app(CollectionStateService::class);
        $service->transition($collection, Collection::STATUS_PENDING, $user);

        Http::assertSent(
            fn ($request) =>
            str_contains($request->body(), $collection->name)
            && str_contains($request->body(), $user->email)
        );
    }

    public function test_it_does_not_send_slack_for_pending_to_active_transition(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        config()->set('services.slack_webhook.url', 'https://hooks.slack.com/test');

        [$custodianUser, $collection] = $this->makeCustodianUserAndCollection();
        $collection->transitionTo(Collection::STATUS_PENDING);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $service = app(CollectionStateService::class);
        $service->transition($collection, Collection::STATUS_ACTIVE, $admin);

        Http::assertNothingSent();
    }

    public function test_it_does_not_send_slack_when_webhook_unset(): void
    {
        Http::fake();
        config()->set('services.slack_webhook.url', null);

        [$user, $collection] = $this->makeCustodianUserAndCollection();

        $service = app(CollectionStateService::class);
        $service->transition($collection, Collection::STATUS_PENDING, $user);

        Http::assertNothingSent();
    }

    /**
     * @return array{0: User, 1: Collection}
     */
    private function makeCustodianUserAndCollection(): array
    {
        $this->disableObservers();

        $custodian = Custodian::factory()->create();
        $user = User::factory()->create();

        CustodianHasUser::create([
            'custodian_id' => $custodian->id,
            'user_id' => $user->id,
        ]);

        $collection = Collection::factory()->create([
            'custodian_id' => $custodian->id,
        ]);
        $collection->modelState()->create([
            'state_id' => State::where('slug', Collection::STATUS_DRAFT)->valueOrFail('id'),
        ]);

        return [$user, $collection];
    }
}
