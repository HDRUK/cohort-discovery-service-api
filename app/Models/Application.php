<?php

namespace App\Models;

use App\Contracts\ValidatableModel;
use Laravel\Passport\Client;

/**
 * An "application" is a Passport OAuth client wearing its domain-name hat -
 * same underlying oauth_clients machinery, but existing here so that
 * ModelBackedRequest can infer it from ApplicationController and pull its
 * validation rules.
 */
class Application extends Client implements ValidatableModel
{
    public function getValidationRules(string $context): array
    {
        return match (strtolower($context)) {
            'store' => [
                'application_name' => 'required|string|max:255',
                // URLs or URNs (e.g. urn:ietf:wg:oauth:2.0:oob), so plain strings
                'redirect_uris' => 'required|array|min:1',
                'redirect_uris.*' => 'required|string|max:2000',
            ],
            default => [],
        };
    }
}
