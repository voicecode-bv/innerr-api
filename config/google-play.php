<?php

return [
    'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
    'service_account_path' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_PATH'),

    'pubsub_audience' => env('GOOGLE_PLAY_PUBSUB_AUDIENCE'),
    'pubsub_subscription' => env('GOOGLE_PLAY_PUBSUB_SUBSCRIPTION'),

    'oauth_token_url' => 'https://oauth2.googleapis.com/token',
    'oauth_scope' => 'https://www.googleapis.com/auth/androidpublisher',
    'access_token_ttl' => 50 * 60,

    'jwks_url' => env('GOOGLE_OIDC_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    'jwks_cache_ttl' => 3600,

    'androidpublisher_base' => 'https://androidpublisher.googleapis.com',
];
