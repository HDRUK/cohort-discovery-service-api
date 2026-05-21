<?php

namespace App\Services\TokenSync;

use App\Models\User;

class UserInfoSyncerService
{
    public function sync(
        User $user,
        ?string $name,
        ?string $externalId,
    ): void {
        $updates = [];

        if ($name && $user->name !== $name) {
            $updates['name'] = $name;
        }

        if ($externalId && $user->external_id !== $externalId) {
            $updates['external_id'] = $externalId;
        }

        if (!empty($updates)) {
            $user->update($updates);
            \Log::info('syncing user info for user (' . $user->id . '): ' . json_encode(array_keys($updates)));
        }
    }
}
