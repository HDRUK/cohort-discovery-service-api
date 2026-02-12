<?php

return [
    'default_max_attempts' => (int) env('MAX_ATTEMPTS', 3),
    'default_lease_seconds' => (int) env("LEASE_SECONDS", 10),
    'default_timeout_seconds' => (int) env('TASK_TIMEOUT_SECONDS', 300),
];
