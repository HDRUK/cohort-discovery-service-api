<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed browser origins
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of frontend origins permitted to POST to the click
    | tracking endpoint. The EnsureBrowserOrigin middleware rejects requests
    | whose Origin header is not in this list. Example:
    | FRONTEND_URLS="https://web.daphne.example,http://localhost:3000"
    |
    */
    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URLS', '')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Rate limit (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Max click POSTs per minute, keyed by authenticated user (falling back to
    | IP). Enforced by the 'click-tracking' rate limiter.
    |
    */
    'rate_limit' => (int) env('CLICK_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Dedup window (seconds)
    |--------------------------------------------------------------------------
    |
    | An identical click (same causer + subject + action) received within this
    | window is treated as a duplicate and not logged a second time.
    |
    */
    'dedup_seconds' => (int) env('CLICK_DEDUP_SECONDS', 5),

];
