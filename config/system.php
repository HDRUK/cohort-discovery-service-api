<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Collection Hosts Key Salts
    |--------------------------------------------------------------------------
    | These salts are used for hashing client keys for collection hosts.
    | Ensure these are set in your environment file for security.
    */
    'salt_1' => env('COLLECTION_HOSTS_KEY_SALT_1'),
    'salt_2' => env('COLLECTION_HOSTS_KEY_SALT_2'),

    /*
    |--------------------------------------------------------------------------
    | Client Basic Authentication
    |--------------------------------------------------------------------------
    | This setting enables or disables basic authentication for clients.
    | It is recommended to keep this enabled for enhanced security.
    |
    */
    'basic_auth_enabled' => env('CLIENT_BASIC_AUTH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Application operation mode
    |--------------------------------------------------------------------------
    | This setting determines the operation mode of the application.
    | It can be set to 'standalone' or 'integrated' based on your need.
    |
    | Supported modes: "standalone", "integrated"
    |
    | Default: "standalone"
    |
    | In standalone mode, the application operates independently and uses its
    | own database and resources. In integrated mode, it connects to external
    | services and databases as part of a larger system.
    |
    */
    'operation_mode' => env('APP_OPERATION_MODE', 'standalone'),
];
