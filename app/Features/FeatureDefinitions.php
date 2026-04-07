<?php

namespace App\Features;

use Laravel\Pennant\Feature;

Feature::define('query-builder', fn () => true);
Feature::define('query-builder-use-leave-confirmation', fn () => true);
/**
 * Show nconcepts/ncollections when viewing a concept
 * This is useful for dev and maybe a feature we want to turn on for prod someday
 */
Feature::define('query-builder-show-concept-stats', fn () => false);
/**
 * Order results based on stats for the search endpoint (or not)
 */
Feature::define('query-builder-use-stats-in-ordering', fn () => false);

/**
 * Attempt to filter by selected datasets when searching for concepts
 */
Feature::define('query-builder-use-collections-in-search', fn () => false);


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
