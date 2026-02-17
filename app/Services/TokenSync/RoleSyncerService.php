<?php

namespace App\Services\TokenSync;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Laravel\Pennant\Feature;

class RoleSyncerService
{
    public function sync(
        User $user,
        array $roleNames,
    ): void {

        if (!Feature::active('integrated-sync-roles-every-request')) {
            return;
        }

        $roleMap = config('claimsaccesscontrol.role_mappings');

        $externalNames = collect($roleNames)
            ->filter()
            ->values()
            ->all();

        $internalNames = collect($roleMap)
            ->filter(fn ($external) => in_array($external, $externalNames, true))
            ->keys()
            ->map(fn ($n) => mb_strtolower($n))
            ->values()
            ->all();

        $roleIds = Role::query()
            ->whereIn(\DB::raw('LOWER(name)'), $internalNames)
            ->pluck('id')
            ->toArray();

        $user->roles()->sync($roleIds);

        \Log::info('syncing roles against user (' . $user->id . '): ' . json_encode($roleIds));

    }



}
