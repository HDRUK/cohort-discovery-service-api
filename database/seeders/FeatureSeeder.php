<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Pennant\Feature;

class FeatureSeeder extends Seeder
{
    private array $features = [
        'query-builder' => true,
        'query-builder-use-leave-confirmation' => true,
        'query-builder-show-concept-stats' => false,
        'query-builder-use-stats-in-ordering' => false,
        'query-builder-use-collections-in-search' => false,
        'constrain-for-bunny-v1' => true,
        'query-builder-allow-nested-groups' => false,
        'flatten-nested-groups' => true,
        'query-nlp' => true,
        'in-app-messenger' => false,
        'admin-more-collection-details' => true,
        'query-builder-use-value-as-number' => false,
        'distribution-use-central-domain' => false,
        'access-banner' => false,
        'query-builder-use-location' => false,
        'query-builder-use-death' => false,
        'query-builder-use-demographic-rule' => false,
        'click-tracking-anonymous' => false,
        'query-builder-use-race' => true,
    ];

    /**
     * Run the database seeds.
     *
     * The list above is the whole truth about which flags exist. Flags already
     * present keep whatever an admin set them to; flags no longer listed are
     * purged, so retiring one needs only an edit here and a deployment step
     * that re-runs this seeder.
     */
    public function run(): void
    {
        foreach ($this->features as $name => $active) {
            $exists = \DB::table('features')
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            if ($active) {
                Feature::activate($name);
            } else {
                Feature::deactivate($name);
            }
        }

        $this->purgeRetiredFeatures();
    }

    private function purgeRetiredFeatures(): void
    {
        $retired = \DB::table('features')
            ->whereNotIn('name', array_keys($this->features))
            ->distinct()
            ->pluck('name')
            ->all();

        if ($retired === []) {
            return;
        }

        Feature::purge($retired);

        $this->command?->info('Purged retired feature flags: '.implode(', ', $retired));
    }
}
