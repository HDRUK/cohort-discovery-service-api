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
        'integrated-sync-workgroups-every-request' => false,
        'integrated-sync-workgroups-first-login' => true,
        'integrated-ensure-default-wgs' => true,
        'integrated-sync-sde-wgs-from-claim' => true,
        'integrated-sync-roles-every-request' => true,
        'integrated-sync-custodians-every-request' => true,
        'admin-more-collection-details' => true,
        'query-builder-use-value-as-number' => false,
    ];

    /**
     * Run the database seeds.
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
    }
}
