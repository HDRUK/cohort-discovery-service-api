<?php

namespace Database\Seeders;

use App\Models\Custodian;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Models\UserHasRole;
use App\Models\Workgroup;
use App\Models\CustodianNetwork;
use App\Models\CustodianNetworkHasCustodian;
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
            'external_custodian_id' => null,
            'external_custodian_name' => null,
        ]);

        $custodian->update([
            'external_custodian_id' => $custodian->id,
            'external_custodian_name' => $custodian->name
        ]);

        $network = CustodianNetwork::factory()->create(
            [
                'name' => 'Demo Network'
            ]
        );

        CustodianNetworkHasCustodian::create([
            'network_id' => $network->id,
            'custodian_id' => $custodian->id,
        ]);


        // --- Admin user ---
        $user = User::create([
            'name' => 'Demo User',
            'email' => config('system.demo_user_email'),
            'password' => Hash::make(config('system.demo_user_password')),
        ]);

        CustodianHasUser::create([
            'user_id' => $user->id,
            'custodian_id' => $custodian->id
        ]);

        $this->addRole($user, 'admin');
        $this->addToWorkgroup($user, 'ADMIN');
        // ----------------------


        // --- Researcher ---
        $researcher = User::create([
           'name' => 'Demo Researcher',
           'email' => config('system.demo_researcher_email'),
           'password' => Hash::make(config('system.demo_researcher_password')),
        ]);
        $this->addToWorkgroup($researcher, 'UK-RESEARCH');
        $researcher->assignRole('user');

        // ----------------------

        $this->call(PersonalAccessClientSeeder::class);
    }

    private function addToWorkgroup(User $user, string $workgroup): void
    {
        $workgroup = Workgroup::where('name', $workgroup)->firstOrFail();
        UserHasWorkgroup::create([
            'user_id' => $user->id,
            'workgroup_id' => $workgroup->id
        ]);
    }

    private function addRole(User $user, string $role): void
    {
        $role = Role::where('name', $role)->firstOrFail();
        UserHasRole::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

}
