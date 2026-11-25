<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Pennant\Feature;

class FeatureSeeder extends Seeder
{
    public function run(): void
    {
        $defined = Feature::defined();

        if ($defined === []) {
            $this->command?->warn('No feature flags are defined; nothing was reconciled.');

            return;
        }

        $this->purgeRetiredFeatures($defined);
        $this->purgeOverridesOfGlobalOnlyFeatures();

        Feature::flushCache();
    }

    private function purgeRetiredFeatures(array $defined): void
    {
        $retired = array_values(array_diff(Feature::stored(), $defined));

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
