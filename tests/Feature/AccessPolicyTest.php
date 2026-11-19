<?php

namespace Tests\Feature;

use App\Enums\ClaimTrust;
use App\Models\User;
use App\Models\UserHasWorkgroup;
use App\Models\UserIdentity;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeIdp;
use Tests\TestCase;

class AccessPolicyTest extends TestCase
{
    private const URL = '/api/v1/access_policy';

    private const USERS_URL = '/api/v1/users';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        UserIdentity::truncate();
        DB::table('activity_log')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        Workgroup::updateOrCreate(['name' => 'DEFAULT'], ['active' => 1]);
        Workgroup::updateOrCreate(['name' => 'UK-RESEARCH'], ['active' => 1]);

        config([
            'system.operation_mode' => 'standalone',
            'sso.enabled' => true,
            'sso.frontend_callback_url' => 'http://fe.test/auth/sso/callback',
            'sso.providers' => ['default' => FakeIdp::providerConfig([
                'label' => 'Single Sign-On',
                'claim_mapping' => ['workgroups_claim' => 'groups', 'roles_claim' => 'roles'],
            ])],
            'claimsaccesscontrol.sync.workgroups.trust' => ClaimTrust::FIRST_LOGIN->value,
            'claimsaccesscontrol.sync.workgroups.authoritative' => false,
            'claimsaccesscontrol.sync.workgroups.ensure_default' => true,
            'claimsaccesscontrol.sync.roles.trust' => ClaimTrust::ALWAYS->value,
            'claimsaccesscontrol.sync.roles.authoritative' => true,
            'claimsaccesscontrol.sync.custodians.trust' => ClaimTrust::NEVER->value,
            'claimsaccesscontrol.sync.custodians.authoritative' => false,
        ]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function ssoUser(): User
    {
        $user = User::factory()->create();

        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'default',
            'provider_sub' => "subject-{$user->id}",
            'email_at_link' => $user->email,
        ]);

        return $user;
    }

    private function useAuthoritativeWorkgroups(): void
    {
        config([
            'claimsaccesscontrol.sync.workgroups.trust' => ClaimTrust::ALWAYS->value,
            'claimsaccesscontrol.sync.workgroups.authoritative' => true,
        ]);
    }

    private function addToWorkgroup(User $user, Workgroup $workgroup)
    {
        return $this->actingAsJwt($this->admin)->postJson(self::USERS_URL."/{$user->id}/workgroup", [
            'workgroup_id' => $workgroup->id,
        ]);
    }

    private function removeFromWorkgroup(User $user, Workgroup $workgroup)
    {
        UserHasWorkgroup::firstOrCreate(['user_id' => $user->id, 'workgroup_id' => $workgroup->id]);

        return $this->actingAsJwt($this->admin)
            ->deleteJson(self::USERS_URL."/{$user->id}/workgroup/{$workgroup->id}");
    }

    private function warnings(): array
    {
        return DB::table('activity_log')
            ->where('event', 'claim_managed_membership_edited')
            ->orderBy('id')
            ->pluck('properties')
            ->map(fn ($properties) => json_decode($properties, true))
            ->all();
    }

    public function test_workgroup_edits_for_an_idp_managed_user_still_succeed(): void
    {
        $this->useAuthoritativeWorkgroups();
        $user = $this->ssoUser();
        $workgroup = Workgroup::where('name', 'UK-RESEARCH')->firstOrFail();

        $this->addToWorkgroup($user, $workgroup)->assertOk();
        $this->removeFromWorkgroup($user, $workgroup)->assertOk();

        $this->assertDatabaseMissing('user_has_workgroups', [
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id,
        ]);
    }

    private function usersIndexQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAsJwt($this->admin)->getJson(self::USERS_URL)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $queryQuotaCounts = fn (string $sql) => str_contains($sql, 'from `queries` where `user_id` = ?');

        return count(array_filter($queries, fn (string $sql) => ! $queryQuotaCounts($sql)));
    }

    public function test_the_users_index_does_not_query_per_user_for_membership_sources(): void
    {
        $this->ssoUser();
        $this->actingAsJwt($this->admin)->getJson(self::USERS_URL)->assertOk();

        $withOne = $this->usersIndexQueryCount();

        foreach (range(1, 4) as $ignored) {
            $this->ssoUser();
        }

        $this->assertSame($withOne, $this->usersIndexQueryCount());
    }

    public function test_the_current_user_payload_has_no_membership_sources(): void
    {
        $this->actingAsJwt($this->admin)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonMissingPath('data.membership_sources');
    }

    public function test_a_non_admin_sees_no_identities_or_membership_sources_on_their_own_payload(): void
    {
        $ssoUser = $this->ssoUser();

        $this->actingAsJwt($ssoUser)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $ssoUser->id)
            ->assertJsonMissingPath('data.identities')
            ->assertJsonMissingPath('data.membership_sources');
    }

    public function test_a_non_admin_cannot_read_identities_or_membership_sources_even_for_themselves(): void
    {
        $ssoUser = $this->ssoUser();

        $this->actingAsJwt($ssoUser)->getJson(self::USERS_URL."/{$ssoUser->id}")->assertForbidden();
        $this->actingAsJwt($ssoUser)->getJson(self::USERS_URL)->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_a_non_admin_cannot_read_the_policy(): void
    {
        $this->actingAsJwt(User::factory()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_a_password_only_users_workgroup_edit_logs_no_warning(): void
    {
        $this->useAuthoritativeWorkgroups();
        $user = User::factory()->create();
        $workgroup = Workgroup::where('name', 'UK-RESEARCH')->firstOrFail();

        $this->addToWorkgroup($user, $workgroup)->assertOk();
        $this->removeFromWorkgroup($user, $workgroup)->assertOk();

        $this->assertSame([], $this->warnings());
    }

    public function test_editing_an_idp_managed_users_workgroups_logs_a_warning(): void
    {
        $this->useAuthoritativeWorkgroups();
        $user = $this->ssoUser();
        $workgroup = Workgroup::where('name', 'UK-RESEARCH')->firstOrFail();

        $this->addToWorkgroup($user, $workgroup)->assertOk();
        $this->removeFromWorkgroup($user, $workgroup)->assertOk();

        $this->assertEquals([
            [
                'relation' => 'workgroups',
                'action' => 'attached',
                'workgroup' => ['id' => $workgroup->id],
                'mode' => 'authoritative',
                'sources' => ['default'],
                'authoritative' => true,
                'restored_on_sync' => false,
            ],
            [
                'relation' => 'workgroups',
                'action' => 'detached',
                'workgroup' => ['id' => $workgroup->id],
                'mode' => 'authoritative',
                'sources' => ['default'],
                'authoritative' => true,
                'restored_on_sync' => false,
            ],
        ], $this->warnings());
    }

    public function test_removing_the_default_workgroup_is_flagged_as_restored_on_sync(): void
    {
        config(['claimsaccesscontrol.sync.workgroups.trust' => ClaimTrust::ALWAYS->value]);
        $user = $this->ssoUser();
        $default = Workgroup::where('name', 'DEFAULT')->firstOrFail();

        $this->removeFromWorkgroup($user, $default)->assertOk();

        $warning = $this->warnings()[0];
        $this->assertSame('additive', $warning['mode']);
        $this->assertTrue($warning['restored_on_sync']);
    }

    public function test_a_pending_first_login_user_is_warned_but_not_once_synced(): void
    {
        $workgroup = Workgroup::where('name', 'UK-RESEARCH')->firstOrFail();

        $this->addToWorkgroup($this->ssoUser(), $workgroup)->assertOk();
        $this->assertSame('first_login', $this->warnings()[0]['mode']);

        $synced = $this->ssoUser();
        $synced->forceFill(['claims_synced_at' => now()])->save();
        $this->addToWorkgroup($synced, $workgroup)->assertOk();

        $this->assertCount(1, $this->warnings());
    }

    public function test_it_returns_the_deployment_policy(): void
    {
        $this->actingAsJwt($this->admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.operation_mode', 'standalone')
            ->assertJsonPath('data.providers', ['default' => 'Single Sign-On'])
            ->assertJsonPath('data.workgroups.mode', 'first_login')
            ->assertJsonPath('data.workgroups.editable', true)
            ->assertJsonPath('data.workgroups.restored_on_sync.0.name', 'DEFAULT')
            ->assertJsonPath('data.roles.mode', 'authoritative')
            ->assertJsonPath('data.roles.editable', false)
            ->assertJsonPath('data.custodians.mode', 'internal')
            ->assertJsonPath('data.custodians.restored_on_sync', []);
    }

    public function test_users_index_and_show_include_membership_sources(): void
    {
        $ssoUser = $this->ssoUser();
        $passwordUser = User::factory()->create();

        $users = collect($this->actingAsJwt($this->admin)->getJson(self::USERS_URL)->assertOk()->json('data'))
            ->keyBy('id');

        $this->assertSame(
            ['mode' => 'authoritative', 'editable' => false, 'sources' => ['default']],
            $users[$ssoUser->id]['membership_sources']['roles']
        );
        $this->assertSame('internal', $users[$passwordUser->id]['membership_sources']['roles']['mode']);

        $this->actingAsJwt($this->admin)->getJson(self::USERS_URL."/{$ssoUser->id}")
            ->assertOk()
            ->assertJsonPath('data.membership_sources.workgroups.mode', 'first_login')
            ->assertJsonPath('data.membership_sources.workgroups.sources', ['default']);
    }
}
