<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Models\Custodian;
use Laravel\Pennant\Feature;

class CustodianSyncerService
{
    public function sync(
        User $user,
        array $custodians,
    ): void {
        if (!Feature::active('integrated-sync-custodians-every-request')) {
            return;
        }

        $rows = collect($custodians)->map(fn ($t) => [
            'external_custodian_id' => $t->id,
            'name' => $t->name,
            'external_custodian_name' => $t->name,
        ])->all();

        if (count($rows) === 0) {
            $user->custodians()->sync([]);
            return;
        }

        Custodian::upsert(
            $rows,
            ['external_custodian_id'],
            ['name', 'external_custodian_name']
        );

        $externalIds = collect($custodians)
            ->pluck('id')
            ->values()
            ->all();

        $custodianIds = Custodian::query()
            ->whereIn('external_custodian_id', $externalIds)
            ->pluck('id')
            ->all();

        $user->custodians()->sync($custodianIds);

    }


}
