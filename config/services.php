<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => '',
        'redirect' => env('APPLE_REDIRECT_URI'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'mapbox' => [
        'public_token' => env('MAPBOX_PUBLIC_TOKEN'),
    ],

    'flare' => [
        'public_key' => env('FLARE_PUBLIC_KEY'),
    ],

    'apple_iap' => [
        'bundle_id' => env('APPLE_IAP_BUNDLE_ID'),
        'issuer_id' => env('APPLE_IAP_ISSUER_ID'),
        'key_id' => env('APPLE_IAP_KEY_ID'),
        'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH'),
        'environment' => env('APPLE_IAP_ENV', 'sandbox'),
    ],

    'google_play' => [
        'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME'),
        'service_account_path' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_PATH'),
        'pubsub_subscription' => env('GOOGLE_PLAY_PUBSUB_SUBSCRIPTION'),
        'pubsub_audience' => env('GOOGLE_PLAY_PUBSUB_AUDIENCE'),
    ],

    'mollie' => [
        'api_key' => env('MOLLIE_API_KEY'),
        'webhook_secret' => env('MOLLIE_WEBHOOK_SECRET'),
    ],

];
