<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Print shop catalog
    |--------------------------------------------------------------------------
    |
    | The app products the shop can offer, keyed by the product id the app
    | sends. This file only describes presentation and artwork constraints;
    | which Printdeal product backs each entry, the order attributes, sizes,
    | and pricing (fixed price or margin on the synced purchase price) all
    | live in the `printdeal_products` table, synced by
    | `php artisan printdeal:sync-products` and managed in the Filament admin.
    |
    | `pdf` describes the artwork the generator produces: trim size in mm,
    | bleed per edge in mm, and how photos map to pages ('per-photo' or a
    | fixed page count that cycles through the photos).
    |
    */

    'products' => [

        'calendar' => [
            'min_photos' => 1,
            'max_photos' => 24,
            // One photo page per month; fewer photos cycle through the year.
            'pdf' => ['width' => 210, 'height' => 297, 'bleed' => 3, 'pages' => 12],
        ],

        'album' => [
            'min_photos' => 1,
            'max_photos' => 50,
            // One photo per page, as many pages as photos.
            'pdf' => ['width' => 210, 'height' => 210, 'bleed' => 3, 'pages' => 'per-photo'],
        ],

        'mug' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single wrap-around canvas.
            'pdf' => ['width' => 200, 'height' => 90, 'bleed' => 3, 'pages' => 1],
        ],

        'tshirt' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single print area (roughly A4 chest print).
            'pdf' => ['width' => 210, 'height' => 297, 'bleed' => 0, 'pages' => 1],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    // ISO 3166-1 alpha-2 codes the app may ship to. Printdeal requires a
    // single shipping country per order, so this also bounds the address form.
    'shipping_countries' => ['NL', 'BE'],

    // Where Mollie sends the user after paying. The app passes this along as
    // the payment's redirect URL; order status itself always comes from the
    // API, so this page only needs to say "all done, return to the app".
    'return_url' => env('PRINT_RETURN_URL', 'https://innerr.app'),

    // Billing address on every Printdeal order: Printdeal invoices us
    // ('onAccount'), the user already paid us via Mollie.
    'billing_address' => [
        'company' => env('PRINT_BILLING_COMPANY', 'Innerr'),
        'firstName' => env('PRINT_BILLING_FIRST_NAME', ''),
        'lastName' => env('PRINT_BILLING_LAST_NAME', ''),
        'email' => env('PRINT_BILLING_EMAIL', ''),
        'street' => env('PRINT_BILLING_STREET', ''),
        'houseNumber' => env('PRINT_BILLING_HOUSE_NUMBER', ''),
        'postalCode' => env('PRINT_BILLING_POSTAL_CODE', ''),
        'city' => env('PRINT_BILLING_CITY', ''),
        'country' => env('PRINT_BILLING_COUNTRY', 'NL'),
        'vatId' => env('PRINT_BILLING_VAT_ID'),
    ],

];
