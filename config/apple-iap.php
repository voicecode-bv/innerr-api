<?php

return [
    'bundle_id' => env('APPLE_IAP_BUNDLE_ID'),
    'issuer_id' => env('APPLE_IAP_ISSUER_ID'),
    'key_id' => env('APPLE_IAP_KEY_ID'),
    'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH'),
    'environment' => env('APPLE_IAP_ENV', 'sandbox'),

    'root_ca_path' => resource_path('certs/apple-root-ca-g3.pem'),

    'base_urls' => [
        'production' => 'https://api.storekit.itunes.apple.com',
        'sandbox' => 'https://api.storekit-sandbox.itunes.apple.com',
    ],

    'jwt_ttl' => 30 * 60,
];
