<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Models\Custodian;
use App\Services\Activity\ActivityLogger;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;

class CustodianSyncerService
{
    public function __construct(
        private readonly ActivityLogger $activity,
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

        $valid = collect($custodians)->filter(
            fn ($t) => is_object($t) && isset($t->id, $t->name) && is_string($t->id) && is_string($t->name)
        );

        if ($valid->count() !== count($custodians)) {
            \Log::warning("Custodian claim sync skipped malformed entries for user {$user->id}", [
                'valid' => $valid->count(),
                'total' => count($custodians),
            ]);
        }

        if ($custodians !== [] && $valid->isEmpty()) {
            return false;
        }

        $authoritative = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_CUSTODIANS);

        $rows = $valid->map(fn ($t) => [
            'external_custodian_id' => $t->id,
            'name' => $t->name,
            'external_custodian_name' => $t->name,
        ])->values()->all();

        if (count($rows) === 0) {
            if ($authoritative) {
                $this->activity->membershipChanged(
                    'claims',
                    'claims_synced',
                    $user,
                    'custodians',
                    $user->custodians()->sync([]),
                    $authoritative,
                    $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_CUSTODIANS),
                    'claims_synced',
                );
            }

            return true;
        }

        Custodian::upsert(
            $rows,
            ['external_custodian_id'],
            ['name', 'external_custodian_name']
        );

        $externalIds = collect($rows)
            ->pluck('external_custodian_id')
            ->values()
            ->all();

        $custodianIds = Custodian::query()
            ->whereIn('external_custodian_id', $externalIds)
            ->pluck('id')
            ->all();

        $changes = $authoritative
            ? $user->custodians()->sync($custodianIds)
            : $user->custodians()->syncWithoutDetaching($custodianIds);

        $this->activity->membershipChanged(
            'claims',
            'claims_synced',
            $user,
            'custodians',
            $changes,
            $authoritative,
            $this->policy->trustedModeOrNever(ClaimSyncPolicy::SUBJECT_CUSTODIANS),
            'claims_synced',
        );

        return true;
    }
}
