<?php

return [
    'user_model' => env('MESSENGER_USER_MODEL', \App\Models\User::class),
    'auth_middleware' => env('MESSENGER_AUTH_MIDDLEWARE', 'decode.jwt'),
    'threads_endpoint' => env('MESSENGER_THREADS_ENDPOINT', 'threads'),
    'messages_endpoint' => env('MESSENGER_MESSAGES_ENDPOINT', 'messages'),
    'threads_table_name' => env('MESSENGER_THREADS_TABLE_NAME', 'messenger_threads'),
    'messages_table_name' => env('MESSENGER_MESSAGES_TABLE_NAME', 'messenger_messages'),
    'thread_users_table_name' => env('MESSENGER_THREAD_USERS_TABLE_NAME', 'messenger_thread_users'),
];
