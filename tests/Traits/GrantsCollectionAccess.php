<?php

namespace Tests\Traits;

use App\Models\Collection;
use App\Models\User;
use App\Models\Workgroup;

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
}
