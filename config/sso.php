<?php

return [
    /*
     * Master switch for external OIDC single sign-on. SSO is only available
     * in standalone operation mode; integrated mode authenticates via the
     * HDR UK Gateway instead.
     */
    'enabled' => env('SSO_ENABLED', false),

    /*
     * Absolute URL on the Cohort Discovery Service frontend that receives the one-time
     * handoff code as ?code=...&provider=... after a successful login.
     */
    'frontend_callback_url' => env('SSO_FRONTEND_CALLBACK_URL'),

    /*
     * Absolute URL on the frontend for failed logins (?error=...). Falls
     * back to frontend_callback_url when unset.
     */
    'frontend_error_url' => env('SSO_FRONTEND_ERROR_URL'),

    /*
     * How long an in-flight authorization transaction (state/nonce/PKCE
     * verifier) survives between redirect and callback.
     */
    'transaction_ttl_seconds' => (int) env('SSO_TXN_TTL_SECONDS', 600),

    /*
     * Lifetime of the single-use handoff code exchanged by the frontend
     * for the access token.
     */
    'handoff_code_ttl_seconds' => (int) env('SSO_HANDOFF_CODE_TTL_SECONDS', 60),

    'discovery_cache_ttl_seconds' => (int) env('SSO_DISCOVERY_CACHE_TTL_SECONDS', 3600),

    'jwks_cache_ttl_seconds' => (int) env('SSO_JWKS_CACHE_TTL_SECONDS', 3600),

    'id_token_leeway_seconds' => (int) env('SSO_ID_TOKEN_LEEWAY_SECONDS', 30),

    /*
     * OIDC providers keyed by slug. The slug appears in URLs
     * (/api/auth/sso/{slug}/...). Additional providers can be added per
     * deployment via config overrides.
     */
    'providers' => [
        'default' => [
            'enabled' => (bool) env('SSO_DEFAULT_ENABLED', false),
            'label' => env('SSO_DEFAULT_LABEL', 'Single Sign-On'),
            'issuer' => env('SSO_DEFAULT_ISSUER'),
            // Defaults to {issuer}/.well-known/openid-configuration
            'discovery_url' => env('SSO_DEFAULT_DISCOVERY_URL'),
            'client_id' => env('SSO_DEFAULT_CLIENT_ID'),
            'client_secret' => env('SSO_DEFAULT_CLIENT_SECRET'),
            'scopes' => env('SSO_DEFAULT_SCOPES', 'openid profile email'),
            // Defaults to url('/api/auth/sso/default/callback')
            'redirect_uri' => env('SSO_DEFAULT_REDIRECT_URI'),
        ],
    ],
];
