<?php

return [

    /**
     * For decoding integrated JWT tokens issued. Needs to match integrated config for
     * federation to work. Not used for our internal generation of codes. Yet.
     */
    'jwt_secret' => env('INTEGRATED_JWT_SECRET'),

    /**
     * The base Integration API URI.
     */
    'api_uri' => env('INTEGRATED_API_URI'),

    /**
     * The Integration OAuth handshake initiation URI.
     */
    'auth_uri' => env('INTEGRATED_AUTHORISATION_URI'),

    /**
     * Pre-registered Daphne Client ID for OAuth/Passport.
     */
    'client_id' => env('INTEGRATED_CLIENT_ID'),

    /**
     * Pre-registered Daphne Client Secret for OAuth/Passport.
     */
    'client_secret' => env('INTEGRATED_CLIENT_SECRET'),

    /**
     * As an Integrating system stores user accounts, and our password field is set
     * to not null, we just store a placeholder. But we do nothing
     * with it currently.
     */
    'placeholder_password' => env('OAUTH_PLACEHOLDER_PASSWORD'),

    /**
     * Our internal callback uri for handling OAuth handshake. Needs to
     * match that registered with the client id and secret generated.
     */
    'internal_oauth_callback_uri' => env('OAUTH_INTERNAL_REDIRECT'),

    'test_user_email' => env('INTEGRATED_TEST_USER_EMAIL'),
    'test_user_password' => env('INTEGRATED_TEST_USER_PASSWORD'),
];
