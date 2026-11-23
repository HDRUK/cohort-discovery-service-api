<?php

namespace Tests\Unit;

use App\Models\Custodian;
use App\Models\User;
use App\Services\Claims\ClaimSyncPolicy;
use App\Services\TokenSync\CustodianSyncerService;
use DB;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class CustodianSyncerServiceTest extends TestCase
{
    private CustodianSyncerService $syncer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_ALWAYS,
            'claimsaccesscontrol.sync.custodians.authoritative' => true,
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->syncer = app(CustodianSyncerService::class);
    }

    private function team(string $id, string $name): object
    {
        return (object) ['id' => $id, 'name' => $name];
    }

    private function custodianNamed(string $name): Custodian
    {
        return Custodian::where('name', $name)->firstOrFail();
    }

    public function test_it_creates_and_attaches_custodians_from_the_claim(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]));

        $this->assertTrue(
            $user->custodians()->where('custodians.id', $this->custodianNamed('Acme Trust')->id)->exists()
        );
    }

    public function test_it_creates_and_attaches_custodians_from_integer_gateway_team_ids(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, [
            (object) ['id' => 21, 'name' => 'Acme Trust'],
            (object) ['id' => 22, 'name' => 'Beta Trust'],
        ]));

        $this->assertSame('21', $this->custodianNamed('Acme Trust')->external_custodian_id);
        $this->assertEqualsCanonicalizing(['Acme Trust', 'Beta Trust'], $user->custodians()->pluck('name')->all());
    }

    public function test_an_integer_team_id_matches_a_custodian_stored_with_the_same_id_as_a_string(): void
    {
        $existing = Custodian::factory()->create(['name' => 'Acme Trust', 'external_custodian_id' => '21']);
        $user = User::factory()->create();

        $this->syncer->sync($user, [(object) ['id' => 21, 'name' => 'Acme Trust']]);

        $this->assertSame(1, Custodian::where('external_custodian_id', '21')->count());
        $this->assertSame([$existing->id], $user->custodians()->pluck('custodians.id')->all());
    }

    public function test_a_custodian_matched_by_name_takes_the_gateway_team_id(): void
    {
        $imported = Custodian::factory()->create(['name' => 'Acme Trust', 'external_custodian_id' => null]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [(object) ['id' => 21, 'name' => 'Acme Trust']]);

        $this->assertSame('21', $imported->refresh()->external_custodian_id);
        $this->assertSame([$imported->id], $user->custodians()->pluck('custodians.id')->all());
    }

    public function test_a_team_cannot_take_over_a_same_named_custodian_linked_to_another_team(): void
    {
        Log::spy();
        $linked = Custodian::factory()->create(['name' => 'Acme Trust', 'external_custodian_id' => '21']);
        $user = User::factory()->create();

        $this->syncer->sync($user, [(object) ['id' => 35, 'name' => 'Acme Trust']]);

        $this->assertSame('21', $linked->refresh()->external_custodian_id);
        $this->assertSame(0, $user->custodians()->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_linked_team_sent_with_an_unlinked_custodians_name_stays_on_its_own_custodian(): void
    {
        $linked = Custodian::factory()->create(['name' => 'Acme Trust', 'external_custodian_id' => '21']);
        $unlinked = Custodian::factory()->create(['name' => 'Beta Trust', 'external_custodian_id' => null]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [(object) ['id' => 21, 'name' => 'Beta Trust']]);

        $this->assertNull($unlinked->refresh()->external_custodian_id);
        $this->assertSame([$linked->id], $user->custodians()->pluck('custodians.id')->all());
    }

    public function test_it_skips_entries_whose_id_is_neither_an_integer_nor_a_string(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [
            (object) ['id' => 21, 'name' => 'Acme Trust'],
            (object) ['id' => true, 'name' => 'Bool Trust'],
            (object) ['id' => 2.5, 'name' => 'Float Trust'],
            (object) ['id' => ['x'], 'name' => 'Array Trust'],
        ]);

        $this->assertSame(['Acme Trust'], Custodian::pluck('name')->all());
        $this->assertSame(['Acme Trust'], $user->custodians()->pluck('name')->all());
    }

    public function test_rejected_teams_are_written_to_the_activity_log(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [
            (object) ['id' => 21, 'name' => 'Acme Trust'],
            (object) ['id' => true, 'name' => 'Bool Trust'],
        ]);

        $activity = Activity::where('log_name', 'claims')
            ->where('description', 'claims_rejected')
            ->whereMorphedTo('subject', $user)
            ->sole();

        $this->assertEquals([
            'relation' => 'custodians',
            'rejected' => [['id' => true, 'name' => 'Bool Trust']],
            'total' => 2,
        ], $activity->properties->toArray());
    }

    public function test_a_claim_where_every_team_is_rejected_logs_an_error(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $this->assertFalse($this->syncer->sync($user, [(object) ['id' => true, 'name' => 'Bool Trust']]));

        Log::shouldHaveReceived('error')->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_claim_where_only_some_teams_are_rejected_logs_a_warning(): void
    {
        Log::spy();
        $user = User::factory()->create();

        $this->syncer->sync($user, [
            (object) ['id' => 21, 'name' => 'Acme Trust'],
            (object) ['id' => true, 'name' => 'Bool Trust'],
        ]);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_it_upserts_on_the_external_id_rather_than_creating_duplicates(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);
        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust Renamed')]);

        $this->assertSame(1, Custodian::where('external_custodian_id', 'ext-1')->count());
        $this->assertSame('Acme Trust Renamed', $this->custodianNamed('Acme Trust Renamed')->name);
    }

    public function test_authoritative_sync_detaches_a_custodian_the_claim_omits(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust'), $this->team('ext-2', 'Beta Trust')]);
        $this->assertSame(2, $user->custodians()->count());

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertSame(['Acme Trust'], $user->custodians()->pluck('name')->all());
    }

    public function test_additive_sync_keeps_a_custodian_the_claim_omits(): void
    {
        config(['claimsaccesscontrol.sync.custodians.authoritative' => false]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust'), $this->team('ext-2', 'Beta Trust')]);
        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertSame(2, $user->custodians()->count());
    }

    public function test_an_absent_claim_leaves_custodians_untouched_under_authoritative_sync(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertFalse($this->syncer->sync($user, null));
        $this->assertSame(1, $user->custodians()->count());
    }

    public function test_a_present_but_empty_claim_clears_custodians_under_authoritative_sync(): void
    {
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertTrue($this->syncer->sync($user, []));
        $this->assertSame(0, $user->custodians()->count());
    }

    public function test_a_present_but_empty_claim_keeps_custodians_under_additive_sync(): void
    {
        config(['claimsaccesscontrol.sync.custodians.authoritative' => false]);
        $user = User::factory()->create();

        $this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]);

        $this->assertTrue($this->syncer->sync($user, []));
        $this->assertSame(1, $user->custodians()->count());
    }

    public function test_no_sync_happens_when_custodians_are_never_trusted(): void
    {
        config(['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_NEVER]);
        $user = User::factory()->create();

        $this->assertFalse($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')]));
        $this->assertSame(0, $user->custodians()->count());
    }

    public function test_first_login_trust_only_syncs_for_an_unsynced_user(): void
    {
        config(['claimsaccesscontrol.sync.custodians.trust' => ClaimSyncPolicy::TRUST_FIRST_LOGIN]);
        $user = User::factory()->create();

        $this->assertTrue($this->syncer->sync($user, [$this->team('ext-1', 'Acme Trust')], null));
        $this->assertFalse($this->syncer->sync($user, [$this->team('ext-2', 'Beta Trust')], now()));

        $this->assertSame(['Acme Trust'], $user->custodians()->pluck('name')->all());
    }
}
