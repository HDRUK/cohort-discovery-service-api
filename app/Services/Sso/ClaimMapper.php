<?php

namespace App\Services\Sso;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Claims\ClaimResolver;
use App\Services\Claims\ClaimSyncPolicy;
use Carbon\CarbonInterface;

class ClaimMapper
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ClaimResolver $resolver,
        private readonly ClaimSyncPolicy $policy,
    ) {
    }

    public function apply(User $user, OidcProviderConfig $provider, OidcAuthResult $result): void
    {
        $mapping = $provider->claimMapping;
        $claimsSyncedAt = $user->claimsSyncedAt();

        $synced = $this->syncWorkgroups($user, $mapping, $result, $claimsSyncedAt);
        $synced = $this->syncRoles($user, $mapping, $result, $claimsSyncedAt) || $synced;
        $synced = $this->syncCustodians($user, $mapping, $result, $claimsSyncedAt) || $synced;

        if ($synced) {
            $user->claims_synced_at = now();
            $user->save();
        }
    }

    private function syncWorkgroups(
        User $user,
        ClaimMappingConfig $mapping,
        OidcAuthResult $result,
        ?CarbonInterface $claimsSyncedAt,
    ): bool {
        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_WORKGROUPS, $claimsSyncedAt)) {
            return false;
        }

        $values = $this->claimValues($result->rawClaims, $mapping->workgroupsClaim);

        // Absent claim means the IdP is not speaking on the subject, which is
        // different from it saying "none" - leave what's there alone
        if ($values === null) {
            return false;
        }

        $workgroupIds = array_values(array_unique(array_merge(
            $this->defaultWorkgroupIds(),
            $this->resolver->workgroupIdsForClaimValues($values),
        )));

        $changes = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_WORKGROUPS)
            ? $user->workgroups()->sync($workgroupIds)
            : $user->workgroups()->syncWithoutDetaching($workgroupIds);

        $this->recordMembershipChange($user, 'workgroups', ClaimSyncPolicy::SUBJECT_WORKGROUPS, $changes);

        return true;
    }

    private function defaultWorkgroupIds(): array
    {
        if (! $this->policy->shouldEnsureDefaultWorkgroup()) {
            return [];
        }

        return $this->resolver->workgroupIdsForNames(['DEFAULT']);
    }

    private function syncRoles(
        User $user,
        ClaimMappingConfig $mapping,
        OidcAuthResult $result,
        ?CarbonInterface $claimsSyncedAt,
    ): bool {
        if (! $mapping->rolesClaim) {
            return false;
        }

        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_ROLES, $claimsSyncedAt)) {
            return false;
        }

        $values = $this->claimValues($result->rawClaims, $mapping->rolesClaim);

        if ($values === null) {
            return false;
        }

        $roleIds = $this->resolver->roleIdsForClaimValues($values);

        $changes = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_ROLES)
            ? $user->roles()->sync($roleIds)
            : $user->roles()->syncWithoutDetaching($roleIds);

        $this->recordMembershipChange($user, 'roles', ClaimSyncPolicy::SUBJECT_ROLES, $changes);

        return true;
    }

    private function syncCustodians(
        User $user,
        ClaimMappingConfig $mapping,
        OidcAuthResult $result,
        ?CarbonInterface $claimsSyncedAt,
    ): bool {
        if (! $mapping->custodiansClaim) {
            return false;
        }

        if (! $this->policy->shouldSync(ClaimSyncPolicy::SUBJECT_CUSTODIANS, $claimsSyncedAt)) {
            return false;
        }

        $values = $this->claimValues($result->rawClaims, $mapping->custodiansClaim);

        if ($values === null) {
            return false;
        }

        $custodianIds = $this->resolver->custodianIdsForClaimValues($values);

        $changes = $this->policy->isAuthoritative(ClaimSyncPolicy::SUBJECT_CUSTODIANS)
            ? $user->custodians()->sync($custodianIds)
            : $user->custodians()->syncWithoutDetaching($custodianIds);

        $this->recordMembershipChange($user, 'custodians', ClaimSyncPolicy::SUBJECT_CUSTODIANS, $changes);

        return true;
    }

    private function recordMembershipChange(User $user, string $relation, string $subject, array $changes): void
    {
        if ($changes['attached'] === [] && $changes['detached'] === []) {
            return;
        }

        $this->activity->custom('sso', 'claims_mapped', $user, [
            'relation' => $relation,
            'trust' => $this->policy->trustedModeOrNever($subject),
            'authoritative' => $this->policy->isAuthoritative($subject),
            'attached' => array_values($changes['attached']),
            'detached' => array_values($changes['detached']),
        ]);
    }

    /**
     * Read a claim as a list of strings.
     *
     * Returns null when the claim is absent (IdP said nothing) and [] when it
     * is present but empty (IdP said "none") - the caller needs to tell those
     * two apart. Some IdPs hand back a JSON-encoded array in a string claim,
     * hence the decode attempt.
     *
     * @return list<string>|null
     */
    private function claimValues(array $claims, string $claimName): ?array
    {
        $raw = $claims[$claimName] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_string'));
            }

            return [$raw];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_string'));
        }

        return [];
    }
}
