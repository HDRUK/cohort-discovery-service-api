<?php

return [
    'default_max_attempts' => env('MAX_ATTEMPTS', 3),
    'default_lease_seconds' => env("LEASE_SECONDS", 60),
    'default_timeout_minutes' => env('TASK_TIMEOUT_MINUTES', 5),
];
