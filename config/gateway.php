<?php

return [

    /**
     * For decoding Gateway JWT tokens issued. Needs to match Gateway config for
     * federation to work. Not used for our internal generation of codes. Yet.
     */
    'jwt_secret' => env('GW_JWT_SECRET'),

    /**
     * The base Gateway API URI.
     */
    'api_uri' => env('GW_API_URI'),

    /**
     * The Gateway OAuth handshake initiation URI.
     */
    'auth_uri' => env('GW_AUTHORISATION_URI'),

    /**
     * Pre-registered Daphne Client ID for OAuth/Passport.
     */
    'client_id' => env('GW_CLIENT_ID'),

    /**
     * Pre-registered Daphne Client Secret for OAuth/Passport.
     */
    'client_secret' => env('GW_CLIENT_SECRET'),

    /**
     * As Gateway stores user accounts, and our password field is set
     * to not null, we just store a placeholder. But we do nothing
     * with it currently.
     */
    'placeholder_password' => env('OAUTH_PLACEHOLDER_PASSWORD'),

    /**
     * Our internal callback uri for handling OAuth handshake. Needs to
     * match that registered with the client id and secret generated.
     */
    'internal_oauth_callback_uri' => env('OAUTH_INTERNAL_REDIRECT'),
];