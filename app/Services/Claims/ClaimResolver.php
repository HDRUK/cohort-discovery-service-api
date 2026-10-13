<?php

namespace App\Services\Claims;

use App\Models\Custodian;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ClaimResolver
{
    public function workgroupIdsForClaimValues(array $claimValues): array
    {
        return $this->workgroupIdsForNames(
            $this->localNames('claimsaccesscontrol.workgroup_mappings', $claimValues)
        );
    }

    public function workgroupIdsForNames(array $names): array
    {
        $normalised = $this->normalise($names);

        if ($normalised === []) {
            return [];
        }

        return Workgroup::query()
            ->whereIn(DB::raw('LOWER(name)'), $normalised)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function roleIdsForClaimValues(array $claimValues): array
    {
        $normalised = $this->normalise(
            $this->localNames('claimsaccesscontrol.role_mappings', $claimValues)
        );

        if ($normalised === []) {
            return [];
        }

        return Role::query()
            ->whereIn(DB::raw('LOWER(name)'), $normalised)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolve claim values directly to existing Custodian rows, matched
     * case-insensitively on name. Unlike workgroups/roles there is no
     * local mapping table and nothing is created on the fly - in
     * standalone mode custodians are provisioned by an admin, and a claim
     * value with no matching record is simply ignored.
     */
    public function custodianIdsForClaimValues(array $claimValues): array
    {
        $normalised = $this->normalise($claimValues);

        if ($normalised === []) {
            return [];
        }

        return Custodian::query()
            ->whereIn(DB::raw('LOWER(name)'), $normalised)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function localNames(string $configKey, array $claimValues): array
    {
        $wanted = $this->normalise($claimValues);

        if ($wanted === []) {
            return [];
        }

        return collect(config($configKey, []))
            ->filter(fn ($claimValue) => in_array(mb_strtolower((string) $claimValue), $wanted, true))
            ->keys()
            ->all();
    }

    private function normalise(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->map(fn ($value) => mb_strtolower($value))
            ->unique()
            ->values()
            ->all();
    }
}
