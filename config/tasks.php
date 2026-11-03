<?php

return [
    'default_max_attempts' => (int) env('MAX_ATTEMPTS', 3),
    'default_lease_seconds' => (int) env("LEASE_SECONDS", 10),
    'a_type_timeout_seconds' => (int) env('TASK_A_TIMEOUT_SECONDS', env('TASK_TIMEOUT_SECONDS', 300)),
    'b_type_timeout_seconds' => (int) env('TASK_B_TIMEOUT_SECONDS', env('TASK_TIMEOUT_SECONDS', 300)),
];
