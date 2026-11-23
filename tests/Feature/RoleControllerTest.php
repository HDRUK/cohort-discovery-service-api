<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleControllerTest extends TestCase
{
    private const BASE_URL = '/api/v1/roles';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMiddleware();
    }

    public function test_admin_can_list_roles(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAsJwt($admin)
            ->getJson(self::BASE_URL)
            ->assertOk()
            ->assertJsonFragment(['id' => Role::findByName('custodian')->id, 'name' => 'custodian']);
    }

    public function test_non_admin_cannot_list_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAsJwt($user)
            ->getJson(self::BASE_URL)
            ->assertForbidden();
    }
}
