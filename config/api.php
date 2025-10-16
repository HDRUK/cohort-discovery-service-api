<?php

return [
    'rate_limit' => env('API_RATE_LIMIT', 1000),
    'per_page' => env('DEFAULT_PER_PAGE', 25),
    'default_max_attemps' => env('DEFAULT_MAX_ATTEMPTS', 3),
    'jwt_secret' => env('JWT_SECRET', '12345abcde'),
];
