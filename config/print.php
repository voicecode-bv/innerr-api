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

        'puzzle' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single landscape canvas, sized for a ~500-piece puzzle. Adjust
            // to the mapped product's real dimensions before going live.
            'pdf' => ['width' => 380, 'height' => 280, 'bleed' => 3, 'pages' => 1],
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

    // Printdeal v2 delivery method id sent with every order (1 = standard).
    'delivery_method' => env('PRINTDEAL_DELIVERY_METHOD', 1),

    // Where Mollie sends the user after paying. The app passes this along as
    // the payment's redirect URL; order status itself always comes from the
    // API, so this page only needs to say "all done, return to the app".
    'return_url' => env('PRINT_RETURN_URL', 'https://innerr.app'),

    // Billing address on every Printdeal order: Printdeal invoices us
    // ('onAccount'), the user already paid us via Mollie.
    'billing_address' => [
        'company' => 'Voicecode B.V.',
        'firstName' => 'Michael',
        'lastName' => 'Blijleven',
        'email' => 'michael@voicecode.nl',
        'street' => 'Koekoekslaan',
        'houseNumber' => '9',
        'postalCode' => '3121XJ',
        'city' => 'Schiedam',
        'country' => 'NL',
        'vatId' => 'NL858816866B01',
    ],

];
