<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    // Just for demonstration purposes for now
    private array $perms = [
        'cohorts:create',
        'cohorts:read',
        'cohorts:update',
        'cohorts:delete',
        'cohorts:query',
    ];

    private array $roles = [
        'default' => [],
        'admin' => [
            'cohorts:create',
            'cohorts:read',
            'cohorts:update',
            'cohorts:delete',
            'cohorts:query',
        ],
        'custodian' => [
            'cohorts:create',
            'cohorts:read',
            'cohorts:update',
            'cohorts:query',
        ],
        'researcher' => [
            'cohorts:read',
            'cohorts:query',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create default system wide permissions
        foreach ($this->perms as $p) {
            Permission::create([
                'name' => $p,
            ]);
        }

        // Create default system wide roles
        foreach ($this->roles as $key => $value) {
            $role = Role::create([
                'name' => $key,
            ]);

            foreach ($value as $perm) {
                $role->givePermissionTo($perm);
            }
        }
    }
}
