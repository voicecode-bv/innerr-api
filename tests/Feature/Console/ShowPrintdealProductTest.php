<?php

use Illuminate\Support\Facades\Http;

it('prints the attribute schema of a product', function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);

    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products/sku-tshirt' => Http::response([
            'name' => ['en-EN' => 'Basic T-shirt', 'nl-NL' => 'Basic T-shirt'],
            'attributes' => [
                [
                    'name' => 'Gender Types',
                    'nameTranslations' => ['en-EN' => 'Gender'],
                    'values' => [
                        ['name' => 'Unisex', 'nameTranslations' => ['en-EN' => 'Unisex']],
                        ['name' => 'Men', 'nameTranslations' => ['en-EN' => 'Men']],
                    ],
                ],
            ],
            'quantities' => [1, 5, 10],
        ]),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:product', ['sku' => 'sku-tshirt'])
        ->expectsOutputToContain('Basic T-shirt')
        ->expectsOutputToContain('Gender Types')
        ->expectsOutputToContain('- Unisex')
        ->expectsOutputToContain('Orderable quantities: 1, 5, 10')
        ->assertSuccessful();
});
