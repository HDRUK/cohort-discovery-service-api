<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nlp' => [
        'base_uri' => env('COHORT_DISCOVER_NLP_SERVICE_BASE_URI'),
    ],

    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        'issuer' => env('OIDC_ISSUER_URL'),
        'audience' => env('OIDC_AUDIENCE'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'userinfo_endpoint' => env('OIDC_USERINFO_ENDPOINT'),
        'discovery_cache_ttl_seconds' => env('OIDC_DISCOVERY_CACHE_TTL_SECONDS', 300),
        'jwks_cache_ttl_seconds' => env('OIDC_JWKS_CACHE_TTL_SECONDS', 300),
        'http_timeout_seconds' => env('OIDC_HTTP_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => env('OIDC_CONNECT_TIMEOUT_SECONDS', 3),
        'clock_skew_seconds' => env('OIDC_CLOCK_SKEW_SECONDS', 60),
    ],
];
