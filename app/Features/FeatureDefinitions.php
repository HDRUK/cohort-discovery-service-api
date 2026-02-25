<?php

namespace App\Features;

use Laravel\Pennant\Feature;

Feature::define('query-builder', fn () => true);
Feature::define('constrain-for-bunny-v1', fn () => true);
Feature::define('query-nlp', fn () => true);
Feature::define('in-app-messenger', fn () => false);


/*
 --- Workgroup Behaviour Flags ----
*/

/**
 * Always sync workgroups from the token
 * To be used if you're managing workgroups externally
 */
Feature::define('integrated-sync-workgroups-every-request', fn () => false);

/**
 * Sync workgroups from the token ONLY the first time
 * To be used if you're managing workgroups internally but
 * want to sync them on first login so a user is added to some default WGs
 */
Feature::define('integrated-sync-workgroups-first-login', fn () => true);

/**
 * Ensure default workgroups are present
 */
Feature::define('integrated-ensure-default-wgs', fn () => true);

/**
 * If true and token claim cohort_discovery_nhs_sde = true,
 * also add NHS-SDE related workgroups as default if default workgroups are added
 */
Feature::define('integrated-sync-sde-wgs-from-claim', fn () => true);


/*
 --- Roles and Teams Behaviour Flags ----
*/

Feature::define('integrated-sync-roles-every-request', fn () => true);

Feature::define('integrated-sync-custodians-every-request', fn () => true);
