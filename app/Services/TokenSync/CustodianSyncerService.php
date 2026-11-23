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

        [$valid, $rejected] = collect($custodians)->partition(fn ($team) => $this->isWellFormedTeam($team));

        if ($rejected->isNotEmpty()) {
            $this->reportRejectedTeams($user, $rejected->values()->all(), count($custodians));
        }

        if ($custodians !== [] && $valid->isEmpty()) {
            return false;
        }

        $authoritative = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_CUSTODIANS);

        $rows = $valid->map(fn ($t) => [
            'external_custodian_id' => (string) $t->id,
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

        $this->linkUnlinkedCustodiansByName($rows);

        Custodian::upsert(
            $rows,
            ['external_custodian_id'],
            ['name', 'external_custodian_name']
        );

        $externalIds = collect($rows)
            ->pluck('external_custodian_id')
            ->values()
            ->all();

        $linked = Custodian::query()
            ->whereIn('external_custodian_id', $externalIds)
            ->pluck('id', 'external_custodian_id');

        $unlinkable = array_values(array_diff($externalIds, $linked->keys()->all()));

        if ($unlinkable !== []) {
            \Log::warning("Custodian claim sync could not link teams for user {$user->id}: a custodian with the same name is linked to another team", [
                'external_custodian_ids' => $unlinkable,
            ]);
        }

        $custodianIds = $linked->values()->all();

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

    private function linkUnlinkedCustodiansByName(array $rows): void
    {
        foreach ($rows as $row) {
            Custodian::query()
                ->whereNull('external_custodian_id')
                ->where('name', $row['name'])
                ->update([
                    'external_custodian_id' => $row['external_custodian_id'],
                    'external_custodian_name' => $row['external_custodian_name'],
                ]);
        }
    }

    private function isWellFormedTeam(mixed $team): bool
    {
        if (! is_object($team) || ! is_string($team->name ?? null)) {
            return false;
        }

        $id = $team->id ?? null;

        return is_int($id) || is_string($id);
    }

    private function reportRejectedTeams(User $user, array $rejected, int $total): void
    {
        $context = ['rejected' => $rejected, 'total' => $total];

        if (count($rejected) === $total) {
            \Log::error("Custodian claim sync rejected every team for user {$user->id}; custodians left unchanged", $context);
        } else {
            \Log::warning("Custodian claim sync rejected some teams for user {$user->id}", $context);
        }

        $this->activity->custom('claims', 'claims_rejected', $user, [
            'relation' => 'custodians',
            ...$context,
        ], 'claims_rejected');
    }
}
