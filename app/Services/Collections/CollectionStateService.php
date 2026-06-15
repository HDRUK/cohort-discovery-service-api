<?php

namespace App\Services\Collections;

use App\Exceptions\Errors_1xxx\CollectionPermissionsNotMetException as CollectionException;
use App\Models\Collection;
use App\Models\CustodianHasUser;
use App\Models\User;
use App\Services\Notifications\SlackNotifier;

class CollectionStateService
{
    public function __construct(private SlackNotifier $slack)
    {
    }


    /**
     * Determines if the incoming Collection can be transitions to $state by $user
     * based upon user roles assigned.
     *
     * Potentially needs padding out with other flows, this is the bear minimum to
     * ensure that custodians can only transition from Draft -> Pending, and internal
     * admins can transition from Pending -> Active -> Rejected
     *
     * Everything else is handled by zero-trust and rejected.
     */
    public function canUserTransition(Collection $collection, string $state, User $user)
    {
        $isCustodianUser = CustodianHasUser::query()
                ->where('custodian_id', $collection->custodian_id)
                ->where('user_id', $user->id)
                ->exists();

        switch (strtolower($state)) {
            case Collection::STATUS_PENDING:
                if ($isCustodianUser || $user->hasRole('admin')) {
                    return true;
                }

                return false;
            case Collection::STATUS_ACTIVE:
            case Collection::STATUS_REJECTED:
                if ($user->hasRole('admin')) {
                    return true;
                }

                return false;
            default:
                // Bit risky.
                return $collection->canTransitionTo($state);
        }
    }

    public function transition(Collection $collection, string $state, User $user): Collection
    {
        if (! $this->canUserTransition($collection, $state, $user)) {
            throw new CollectionException($state);
        }

        $wasDraft = $collection->isInState(Collection::STATUS_DRAFT);
        $result = $collection->transitionTo($state);

        if ($wasDraft && strtolower($state) === Collection::STATUS_PENDING) {
            $this->slack->sendBlocks([
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => '📋 Collection Activation Request', 'emoji' => true],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Collection:*\n{$collection->name}"],
                        ['type' => 'mrkdwn', 'text' => "*Action:*\nRequested to be made active"],
                        ['type' => 'mrkdwn', 'text' => "*Custodian:*\n{$collection->custodian->name}"],
                        ['type' => 'mrkdwn', 'text' => "*Requested by:*\n{$user->email}"],
                    ],
                ],
            ], "📋 Collection activation request: {$collection->name}", '#2EB67D');
        }

        return $result;
    }
}
