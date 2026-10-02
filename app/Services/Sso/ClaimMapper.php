<?php

namespace App\Services\Sso;

use App\Models\User;
use App\Models\Workgroup;
use App\Services\Activity\ActivityLogger;
use Spatie\Permission\Models\Role;

/**
 * Turns IdP claims into local workgroups and roles.
 *
 * Two dials matter here. The first is whether mapping runs at all - off by
 * default, because most deployments want a Daphne admin deciding who can do
 * what. The second is `authority`: under 'local' claims may only ever add
 * membership, so an admin's manual assignment survives the user's next login;
 * under 'idp' the claim is the whole truth and anything it omits is removed.
 *
 * Getting that second dial wrong is expensive - a present-but-empty claim
 * under 'idp' will strip every workgroup the user has - so 'local' is what
 * you get for any value we don't recognise.
 */
class ClaimMapper
{
    public function __construct(
        private readonly ActivityLogger $activity,
    ) {
    }

    public function apply(User $user, OidcProviderConfig $provider, OidcAuthResult $result): void
    {
        $mapping = $provider->claimMapping;

        if (! $mapping->enabled) {
            return;
        }

        $this->syncWorkgroups($user, $mapping, $result);
        $this->syncRoles($user, $mapping, $result);
    }

    private function syncWorkgroups(User $user, ClaimMappingConfig $mapping, OidcAuthResult $result): void
    {
        $values = $this->claimValues($result->rawClaims, $mapping->workgroupsClaim);

        // Absent claim means the IdP is not speaking on the subject, which is
        // different from it saying "none" - leave what's there alone
        if ($values === null) {
            return;
        }

        $workgroupIds = Workgroup::whereIn('claim_value', $values)->pluck('id')->all();

        $changes = $mapping->isIdpAuthoritative()
            ? $user->workgroups()->sync($workgroupIds)
            : $user->workgroups()->syncWithoutDetaching($workgroupIds);

        $this->recordMembershipChange($user, 'workgroups', $mapping, $changes);
    }

    private function syncRoles(User $user, ClaimMappingConfig $mapping, OidcAuthResult $result): void
    {
        if (! $mapping->rolesClaim || $mapping->roleMap === []) {
            return;
        }

        $values = $this->claimValues($result->rawClaims, $mapping->rolesClaim);

        if ($values === null) {
            return;
        }

        $roleNames = collect($mapping->roleMap)
            ->filter(fn ($local, $claimValue) => in_array((string) $claimValue, $values, true))
            ->values()
            ->map(fn ($local) => mb_strtolower((string) $local))
            ->unique()
            ->all();

        $roleIds = Role::query()
            ->whereIn(\DB::raw('LOWER(name)'), $roleNames)
            ->pluck('id')
            ->all();

        $changes = $mapping->isIdpAuthoritative()
            ? $user->roles()->sync($roleIds)
            : $user->roles()->syncWithoutDetaching($roleIds);

        $this->recordMembershipChange($user, 'roles', $mapping, $changes);
    }

    private function recordMembershipChange(User $user, string $relation, ClaimMappingConfig $mapping, array $changes): void
    {
        if ($changes['attached'] === [] && $changes['detached'] === []) {
            return;
        }

        $this->activity->custom('sso', 'claims_mapped', $user, [
            'relation' => $relation,
            'authority' => $mapping->isIdpAuthoritative() ? 'idp' : 'local',
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
