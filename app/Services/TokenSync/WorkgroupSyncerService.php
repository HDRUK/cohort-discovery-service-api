<?php

namespace App\Services\TokenSync;

use App\Models\User;
use App\Models\Workgroup;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class WorkgroupSyncerService
{
    private array $nhsWorkgroups = ['NHS-SDE', 'UK-INDUSTRY', 'UK-RESEARCH'];


    public function sync(
        User $user,
        array $tokenWorkgroups,
        bool $hasSdeApproval,
    ): void {

        $syncEveryRequest = Feature::active('integrated-sync-workgroups-every-request');
        $syncFirstLogin   = Feature::active('integrated-sync-workgroups-first-login');

        $performSync =
            $syncEveryRequest ||
            ($syncFirstLogin && is_null($user->integrated_wg_synced_at));

        \Log::info('syncEveryRequest ? '. json_encode($syncEveryRequest));
        \Log::info('syncFirstLogin ? '. json_encode($syncFirstLogin));
        \Log::info('performing sync ? '. json_encode($performSync));

        if (!$performSync) {
            return;
        }

        $defaultWgIds = $this->defaultWorkgroupIds($hasSdeApproval);
        $mappedIds = $this->mapTokenWorkgroupsToInternalIds($tokenWorkgroups);

        \Log::info('default IDs found = '. json_encode($defaultWgIds));
        \Log::info('mapped IDs found = '. json_encode($mappedIds));

        $finalIds = array_values(array_unique(array_merge($defaultWgIds, $mappedIds)));

        $user->workgroups()->sync($finalIds);
        $user->integrated_wg_synced_at = now();
        $user->save();
    }


    private function defaultWorkgroupIds(bool $hasSdeApproval): array
    {
        if (!Feature::active('integrated-ensure-default-wgs')) {
            return [];
        }

        $ids = [];
        $defaultId = Workgroup::where('name', 'DEFAULT')->value('id');
        if ($defaultId) {
            $ids[] = (int) $defaultId;
        }

        if (
            $hasSdeApproval &&
            Feature::active('integrated-sync-sde-wgs-from-claim')
        ) {
            $sdeIds = Workgroup::whereIn('name', $this->nhsWorkgroups)
                ->pluck('id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $ids = array_merge($ids, $sdeIds);
        }

        return array_values(array_unique($ids));
    }


    private function mapTokenWorkgroupsToInternalIds(array $tokenWorkgroups): array
    {
        $workgroupMap = config('claimsaccesscontrol.workgroup_mappings', []);


        // Check if externalNames match either the keys (internal names that are also external)
        // or the values (configured external names) in the workgroupMap
        $tokenWorkgroupsNorm = array_map('mb_strtolower', $tokenWorkgroups);

        \Log::info('Token names normalised = '. json_encode($tokenWorkgroupsNorm));
        $internalNames = collect($workgroupMap)
            ->filter(
                fn ($externalValue, $internalKey) =>
                in_array(mb_strtolower($internalKey), $tokenWorkgroupsNorm, true) ||
                in_array(mb_strtolower($externalValue), $tokenWorkgroupsNorm, true)
            )
            ->keys()
            ->values()
            ->all();

        \Log::info('internalNames found = '. json_encode($internalNames));

        if (count($internalNames) === 0) {
            return [];
        }

        return Workgroup::query()
            ->whereIn(DB::raw('LOWER(name)'), $internalNames)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->toArray();
    }
}
