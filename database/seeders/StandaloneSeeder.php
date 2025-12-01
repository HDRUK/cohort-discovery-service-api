<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserHasRole;
use App\Models\UserHasWorkgroup;
use App\Models\Workgroup;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StandaloneSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test Researcher',
            'email' => 'iam.a.researcher@hdruk.ac.uk',
            'password' => Hash::make('iam'),
        ]);

        $adminWorkgroup = Workgroup::where('name', 'ADMIN')->firstOrFail();

        UserHasWorkgroup::create([
            'user_id' => $user->id,
            'workgroup_id' => $adminWorkgroup->id
        ]);

        $adminRole = Role::where('name', 'admin')->firstOrFail();

        UserHasRole::create([
            'user_id' => $user->id,
            'role_id' => $adminRole->id
        ]);

    }
}
