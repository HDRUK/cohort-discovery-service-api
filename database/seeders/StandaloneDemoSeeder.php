<?php

namespace Database\Seeders;

use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Models\UserHasRole;
use App\Models\Workgroup;
use App\Models\UserHasWorkgroup;
use DB;
use Hash;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class StandaloneDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Custodian::truncate();
        User::truncate();
        UserHasRole::truncate();
        UserHasWorkgroup::truncate();
        CustodianHasUser::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $custodian = Custodian::create([
            'name' => 'Demo Custodian',
            'gateway_team_id' => null,
            'gateway_team_name' => null,
        ]);

        $custodian->update([
            'gateway_team_id' => $custodian->id,
            'gateway_team_name' => $custodian->name
        ]);

        $user = User::create([
            'name' => 'Demo User',
            'email' => config('system.demo_user_email'),
            'password' => Hash::make(config('system.demo_user_password')),
        ]);

        CustodianHasUser::create([
            'user_id' => $user->id,
            'custodian_id' => $custodian->id
        ]);

        UserHasRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('name', 'admin')->first()->id,
        ]);


        $adminWorkgroup = Workgroup::where('name', 'ADMIN')->firstOrFail();

        UserHasWorkgroup::create([
            'user_id' => $user->id,
            'workgroup_id' => $adminWorkgroup->id
        ]);

    }
}
