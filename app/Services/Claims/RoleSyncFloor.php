<?php

namespace App\Services\Claims;

use App\Models\User;

class RoleSyncFloor
{
    private const DEFAULT_ROLE = 'user';

    private const ADMIN_ROLE = 'admin';

    public function __construct(
        private readonly ClaimResolver $resolver,
        private readonly ClaimSyncPolicy $policy,
    ) {
    }

    public function apply(User $user, array $roleIds): array
    {
        $floored = array_values(array_unique(array_merge(
            $this->defaultRoleIds(),
            $roleIds,
        )));

        return $this->retainLastAdmin($user, $floored);
    }

    private function defaultRoleIds(): array
    {
        if (! $this->policy->shouldEnsureDefaultRole()) {
            return [];
        }

        return $this->resolver->roleIdsForNames([self::DEFAULT_ROLE]);
    }

    /**
     * An authoritative sync that drops the only remaining admin leaves a
     * deployment nobody can administer and no endpoint can repair.
     */
    private function retainLastAdmin(User $user, array $roleIds): array
    {
        $adminIds = $this->resolver->roleIdsForNames([self::ADMIN_ROLE]);

        if ($adminIds === [] || array_intersect($adminIds, $roleIds) !== []) {
            return $roleIds;
        }

        if (! $user->roles()->whereIn('roles.id', $adminIds)->exists()) {
            return $roleIds;
        }

        $admins = User::whereHas('roles', fn ($query) => $query->whereIn('roles.id', $adminIds))->count();

        if ($admins > 1) {
            return $roleIds;
        }

        \Log::warning(
            "Claim sync would have removed the last admin role from user {$user->id}; retaining it"
        );

        return array_values(array_unique(array_merge($roleIds, $adminIds)));
    }
}
