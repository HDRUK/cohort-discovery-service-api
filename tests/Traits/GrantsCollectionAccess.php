<?php

namespace Tests\Traits;

use App\Models\Collection;
use App\Models\User;
use App\Models\Workgroup;
use DB;

trait GrantsCollectionAccess
{
    protected function grantCollectionAccess(User $user, Collection ...$collections): void
    {
        $workgroup = Workgroup::firstOrCreate(['name' => 'TEST-COLLECTION-ACCESS']);
        $user->workgroups()->syncWithoutDetaching([$workgroup->id]);

        foreach ($collections as $collection) {
            $collection->setState(Collection::STATUS_ACTIVE);
            $workgroup->collections()->syncWithoutDetaching([$collection->id]);
        }
    }

    protected function resetCollectionAccess(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('user_has_workgroups')->truncate();
        DB::table('workgroup_has_collection')->truncate();
        DB::table('custodian_has_users')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
}
