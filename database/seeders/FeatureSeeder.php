<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Pennant\Feature;

class FeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * config('features.flags') is the whole truth about which flags exist. Flags already
     * present keep whatever an admin set them to; flags no longer listed are
     * purged, so retiring one needs only an edit here and a deployment step
     * that re-runs this seeder.
     */
    public function run(): void
    {
        foreach (config('features.flags') as $name => $flag) {
            $exists = \DB::table('features')
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            \DB::table('features')->insert([
                'name' => $name,
                'scope' => Feature::serializeScope(null),
                'value' => json_encode($flag['default']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->purgeRetiredFeatures();
        $this->purgeOverridesOfGlobalOnlyFeatures();

        Feature::flushCache();
    }

    private function purgeRetiredFeatures(): void
    {
        $retired = \DB::table('features')
            ->whereNotIn('name', array_keys(config('features.flags')))
            ->distinct()
            ->pluck('name')
            ->all();

        if ($retired === []) {
            return;
        }

        Feature::purge($retired);

        $this->command?->info('Purged retired feature flags: '.implode(', ', $retired));
    }

    private function purgeOverridesOfGlobalOnlyFeatures(): void
    {
        $globalOnly = collect(config('features.flags'))
            ->reject(fn (array $flag) => $flag['overridable'] ?? true)
            ->keys();

        \DB::table('features')
            ->whereIn('name', $globalOnly)
            ->where('scope', '!=', Feature::serializeScope(null))
            ->delete();
    }
}
