<?php

return [
    'default_max_attempts' => env('MAX_ATTEMPTS', 3),
    'default_lease_seconds' => env("LEASE_SECONDS", 60),
    'default_timeout_seconds' => env('TASK_TIMEOUT_SECONDS', 300),
];
