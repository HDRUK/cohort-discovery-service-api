<?php

namespace Tests\Unit;

use App\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RBACTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_application_can_assign_roles(): void
    {
        User::factory(1)->create();
        $user = User::find(1)->first();
        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_the_application_is_aware_of_specific_role_permissions(): void
    {
        User::factory(1)->create();
        $user = User::find(1)->first();

        $user->assignRole('admin');

        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can('cohorts:query'));

        $user->removeRole('admin');
        $user->assignRole('custodian');

        $this->assertTrue($user->hasRole('custodian'));
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->can('cohorts:delete'));

        $user->removeRole('custodian');
        $user->assignRole('researcher');

        $this->assertTrue($user->hasRole('researcher'));
        $this->assertFalse($user->hasRole('custodian'));

        $this->assertTrue($user->can('cohorts:read'));
        $this->assertTrue($user->can('cohorts:query'));

        $this->assertFalse($user->can('cohorts:create'));
        $this->assertFalse($user->can('cohorts:update'));
        $this->assertFalse($user->can('cohorts:delete'));
    }
}
