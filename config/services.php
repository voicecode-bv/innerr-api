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

    /*
     * FileFlux is een externe Laravel-app met ffmpeg-runtime die video-uploads
     * voor ons transcodeert tot HLS. Wanneer `enabled` true is, dispatcht
     * PostController een SubmitVideoToFileFlux job ipv lokale TranscodeVideo;
     * de lokale ffmpeg-fallback blijft actief voor avatars, cirkel-photos, en
     * als veiligheidsnet bij FileFlux outage.
     */
    'fileflux' => [
        'enabled' => env('FILEFLUX_ENABLED', false),
        // De codingmonkeys/laravel-fileflux package leest project_id, api_key,
        // webhook.signature zelf uit env (zie vendor/.../config/fileflux.php).
        // We mirroren ze hier alleen voor handige toegang in onze eigen code.
        'project_id' => env('FILEFLUX_PROJECT_ID'),
        'api_key' => env('FILEFLUX_API_KEY'),
        'webhook_secret' => env('FILEFLUX_WEBHOOK_SIGNATURE'),
        // Eén env voor de callback URL — gedeeld met de package's default in
        // `config/fileflux.php`. Elvis-operator zodat een lege `FILEFLUX_WEBHOOK_URL=`
        // in .env terugvalt naar APP_URL (env() doet dat alleen voor `null`, niet
        // voor lege string). In productie altijd expliciet zetten.
        'callback_url' => env('FILEFLUX_WEBHOOK_URL')
            ?: rtrim((string) config('app.url'), '/').'/api/webhooks/media/fileflux',
        'job_timeout_minutes' => env('FILEFLUX_JOB_TIMEOUT_MINUTES', 30),

        /*
         * HLS ladder config. Verschil tussen renditions is bewust gefaseerd —
         * 1080p voor wifi, 720p voor goed 4G, 480p als veilige fallback bij
         * slecht 4G/3G. HEVC kan later toegevoegd worden door codec='hevc'
         * varianten parallel naast deze H.264 ladder te zetten.
         */
        'ladder' => [
            'codec' => 'h264',
            'segment_duration' => 6,
            'audio' => [
                'codec' => 'aac',
                'bitrate' => 128,
                'channels' => 2,
            ],
            'renditions' => [
                ['name' => 'v1080', 'height' => 1080, 'video_bitrate' => 5000],
                ['name' => 'v720', 'height' => 720, 'video_bitrate' => 2800],
                ['name' => 'v480', 'height' => 480, 'video_bitrate' => 1200],
            ],
            'poster' => [
                'enabled' => true,
                'filename' => 'poster.jpg',
                'timestamp_seconds' => 1.0,
            ],
        ],
    ],

    'bunny_cdn' => [
        /*
         * Base URL of the Bunny pull zone, e.g. https://media.innerr.app.
         * When null, MediaUrl falls back to the storage disk's own temporaryUrl()
         * (S3 presigned) or to a signed Laravel route in test/local.
         */
        'url' => env('BUNNY_CDN_URL'),

        /*
         * Token Authentication Key from the pull zone Security tab. Required
         * whenever `url` is set. Treat as a secret.
         */
        'token_key' => env('BUNNY_CDN_TOKEN_KEY'),
    ],

    /*
     * Printdeal (drukwerkdeal.nl) print-on-demand API v2. Credentials come
     * from the API Credentials page on the platform and are sent as the
     * `User-ID` (api_key) and `API-Secret` (secret) headers on every request.
     * The product catalog (SKUs, attributes, selling prices) lives in
     * config/print.php.
     */
    'printdeal' => [
        'base_url' => env('PRINTDEAL_BASE_URL', 'https://api.printdeal.com/api'),
        // Webhook subscriptions live on a separate host (same credentials).
        'webhook_base_url' => env('PRINTDEAL_WEBHOOK_BASE_URL', 'https://webhook.api.printdeal.com'),
        'api_key' => env('PRINTDEAL_API_KEY'),
        'secret' => env('PRINTDEAL_SECRET'),

        // Keep true until the catalog mapping is verified against the live
        // account; test orders are accepted but never produced or billed.
        'test_orders' => env('PRINTDEAL_TEST_ORDERS', true),

        // Shared secret embedded in the webhook URL path, since Printdeal
        // does not sign webhook payloads.
        'webhook_token' => env('PRINTDEAL_WEBHOOK_TOKEN'),
    ],

];
