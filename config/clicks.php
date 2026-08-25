<?php

return [

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

];
