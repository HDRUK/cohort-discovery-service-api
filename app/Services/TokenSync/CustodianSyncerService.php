<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Models\Custodian;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;

class CustodianSyncerService
{
    public function __construct(
        private readonly ClaimSyncPolicy $policy,
    ) {
    }

    public function sync(
        User $user,
        ?array $custodians,
        ?CarbonInterface $claimsSyncedAt = null,
    ): bool {
        if ($custodians === null) {
            return false;
        }

        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_CUSTODIANS, $claimsSyncedAt)) {
            return false;
        }

        $authoritative = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_CUSTODIANS);

        $rows = collect($custodians)->map(fn ($t) => [
            'external_custodian_id' => $t->id,
            'name' => $t->name,
            'external_custodian_name' => $t->name,
        ])->all();

        if (count($rows) === 0) {
            if ($authoritative) {
                $user->custodians()->sync([]);
            }

            return true;
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

        $authoritative
            ? $user->custodians()->sync($custodianIds)
            : $user->custodians()->syncWithoutDetaching($custodianIds);

        return true;
    }


}
