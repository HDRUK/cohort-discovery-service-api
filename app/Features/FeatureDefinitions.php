<?php

namespace App\Features;

use Laravel\Pennant\Feature;

Feature::define('query-builder', fn () => true);
Feature::define('constrain-for-bunny-v1', fn () => true);
Feature::define('query-nlp', fn () => true);
Feature::define('in-app-messenger', fn () => false);
Feature::define('manage-workgroups-internal', fn () => false);
Feature::define('add-user-to-default-wg', fn () => true);
Feature::define('add-user-to-nhs-sde-wgs', fn () => true);
