<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Verification Code
    |--------------------------------------------------------------------------
    |
    | Controls the lifetime of a one-time verification code, how many incorrect
    | attempts a single code tolerates before it is invalidated, and the
    | minimum number of seconds between two resend requests.
    |
    */

    'code_ttl_minutes' => (int) env('EMAIL_VERIFICATION_CODE_TTL_MINUTES', 15),

    'max_attempts' => (int) env('EMAIL_VERIFICATION_MAX_ATTEMPTS', 5),

    'resend_cooldown_seconds' => (int) env('EMAIL_VERIFICATION_RESEND_COOLDOWN_SECONDS', 60),
];
