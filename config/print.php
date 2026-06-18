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
    | `orientation` decides how the page relates to the photo's orientation:
    |   'fixed' — always print at the trim size as written (calendars hang
    |             portrait, mugs wrap, t-shirt chest prints, square albums);
    |             a mismatching photo is cover-cropped to fit.
    |   'auto'  — the product can be produced either way, so the page is
    |             rotated to match the photo: a portrait photo gets a portrait
    |             page and a landscape photo a landscape page. The mapped
    |             Printdeal sku must accept both orientations of the trim size.
    |
    */

    // Print resolution for the generated artwork. Photos are rendered at this
    // DPI, and it drives the recommended photo resolution sent to the app.
    'dpi' => 300,

    // Minimum acceptable resolution (DPI) of the source photo for the chosen
    // product size. Below this an order is refused at checkout rather than
    // printing a visibly soft, paid product. 150 DPI is the common print-
    // quality floor; lower it for large formats viewed from a distance.
    'min_dpi' => 150,

    // VAT (BTW) percentage added on top of the margin to reach the consumer
    // price. Printdeal's API quotes prices EX VAT but invoices INCL 21% VAT,
    // so a margin-based selling price must be grossed up by VAT or the order
    // loses money. The input VAT is reclaimable, so the margin still lands on
    // the net purchase price. Fixed prices are entered incl. VAT, as-is.
    'vat_percent' => 21,

    'products' => [

        'calendar' => [
            'min_photos' => 1,
            'max_photos' => 24,
            // One photo page per month; fewer photos cycle through the year.
            'pdf' => ['width' => 210, 'height' => 297, 'bleed' => 3, 'pages' => 12, 'orientation' => 'fixed'],
        ],

        'album' => [
            'min_photos' => 1,
            'max_photos' => 50,
            // One photo per page, as many pages as photos.
            'pdf' => ['width' => 210, 'height' => 210, 'bleed' => 3, 'pages' => 'per-photo', 'orientation' => 'fixed'],
        ],

        'mug' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single wrap-around canvas.
            'pdf' => ['width' => 200, 'height' => 90, 'bleed' => 3, 'pages' => 1, 'orientation' => 'fixed'],
        ],

        'tshirt' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single print area (roughly A4 chest print).
            'pdf' => ['width' => 210, 'height' => 297, 'bleed' => 0, 'pages' => 1, 'orientation' => 'fixed'],
        ],

        'puzzle' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single canvas, sized for a ~500-piece puzzle; printed portrait or
            // landscape to match the photo. Adjust to the mapped product's real
            // dimensions before going live. The puzzle press expects CMYK
            // artwork delivered as PDF/X-1a:2001 (see the 'pdfx' block below).
            'pdf' => ['width' => 380, 'height' => 280, 'bleed' => 3, 'pages' => 1, 'orientation' => 'auto', 'pdf_x1a' => true],
        ],

        'canvas' => [
            'min_photos' => 1,
            'max_photos' => 1,
            // Single canvas print, printed portrait or landscape to match the
            // photo. The generous bleed covers the wrap around the frame edges;
            // verify against the mapped product's real dimensions before going
            // live.
            'pdf' => ['width' => 400, 'height' => 300, 'bleed' => 30, 'pages' => 1, 'orientation' => 'auto'],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | PDF/X-1a:2001 conversion
    |--------------------------------------------------------------------------
    |
    | Products flagged with `pdf_x1a` (puzzles) must be delivered as a
    | print-ready PDF/X-1a:2001 file: CMYK only, with the output intent ICC
    | profile embedded. The conversion runs the generated RGB PDF through
    | Ghostscript, which separates every colour to the CMYK profile below and
    | tags the document as PDF/X-1a:2001. Ghostscript must be installed on the
    | queue worker that runs SubmitPrintOrder.
    |
    */

    'pdfx' => [

        // Ghostscript binary. Override per environment if it is not on PATH.
        'ghostscript_binary' => env('PRINT_GHOSTSCRIPT_BINARY', 'gs'),

        // CMYK output intent profile. Ships ISO Coated v2 (FOGRA39), the
        // European coated-paper standard that matches Adobe's "Coated FOGRA39"
        // PDF/X preset. Replace the file (or point the env at another path) if
        // the printer specifies a different printing condition.
        'icc_profile' => env('PRINT_PDFX_ICC_PROFILE', resource_path('icc/ISOcoated_v2.icc')),

        // Human-readable identifier recorded in the PDF's output intent.
        'output_condition' => env('PRINT_PDFX_OUTPUT_CONDITION', 'Coated FOGRA39 (ISO 12647-2:2004)'),

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
    'return_url' => env('PRINT_RETURN_URL', 'https://innerr.app/orders'),

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
