<?php

namespace App\Policies;

use App\Models\Custodian;
use App\Models\User;
use Illuminate\Support\Arr;

class CustodianPolicy
{
    /**
     * Determine whether the user can access the model.
     */
    public function access(User $user, Custodian $custodian): bool
    {
        $userObject = null;

        $claims = $this->toArray(request()->attributes->get('jwt_claims', []));
        $userObject = $this->toArray($claims['user'] ?? []);

        if (!$userObject || ($userObject['email'] ?? null) !== $user->email) {
            return false;
        }

        $adminTeamIds = Arr::pluck($userObject['admin_teams'] ?? [], 'id');
        //note: this is currently quite specific to the gateway
        // - we map a custodian to a gateway 'team'
        // - we check if this user is a team admin on this gateway team
        // - the user claims tell us what teams they are admins on
        // - if so, we check if the custodian is linked to this gateway team
        // - access is granted based on this
        return in_array($custodian->gateway_team_id, $adminTeamIds, true);
    }

    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }
        return (array) $value;
    }

}
