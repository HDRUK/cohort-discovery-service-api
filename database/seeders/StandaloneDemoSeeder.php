<?php

namespace Database\Seeders;

use App\Models\Custodian;
use App\Models\User;
use App\Models\UserHasRole;
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
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $custodian = Custodian::create([
            'name' => 'Demo Custodian',
            'gateway_team_id' => null,
            'gateway_team_name' => null,
        ]);

        $user = User::create([
            'name' => 'Demo User',
            'email' => 'demo.user@domain.com',
            'password' => Hash::make(config('system.demo_user_password')),
        ]);

        UserHasRole::create([
            'user_id' => $user->id,
            'role_id' => Role::where('name', 'admin')->first()->id,
        ]);
    }
}
