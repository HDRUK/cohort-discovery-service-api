<?php

namespace App\Traits;

trait Workgroups
{
    protected function normaliseUserWorkgroups(array &$user): void
    {
        $workgroups = $user['workgroups'] ?? [];

        $normalised = [];
        foreach ($workgroups as $workgroup) {
            if (is_array($workgroup) && isset($workgroup['name'])) {
                $normalised[] = strtolower($workgroup['name']);
            }
        }

        $user['workgroups'] = [
            config('claims-access.default_system') => $normalised,
        ];
    }
}
