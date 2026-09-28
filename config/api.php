<?php

return [
    'rate_limit' => env('API_RATE_LIMIT', 1000),
    'click_rate_limit' => env('CLICK_RATE_LIMIT', 60),
    'per_page' => env('DEFAULT_PER_PAGE', 25),
    'jwt_secret' => env('JWT_SECRET', '12345abcde'),
];
