<?php

use App\Models\PrintdealProduct;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);
});

it('prints the attribute schema of a product', function () {
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

it('resolves a local printdeal_products id to its sku', function () {
    $local = PrintdealProduct::factory()->create(['sku' => 'real-sku-uuid']);

    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products/real-sku-uuid' => Http::response([
            'name' => ['en-EN' => 'Basic T-shirt'],
            'attributes' => [],
        ]),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:product', ['sku' => $local->id])
        ->expectsOutputToContain('Resolved local product id to sku real-sku-uuid.')
        ->expectsOutputToContain('Basic T-shirt')
        ->assertSuccessful();
});

it('falls back to the validate endpoint when details return 404', function () {
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products/sku-tshirt/validate' => Http::response([
            'remainingOptions' => [
                ['attribute' => 'Gender Types', 'values' => ['Unisex', 'Men']],
                ['attribute' => 'Size', 'values' => ['S', 'M', 'L']],
            ],
            'selectionStatus' => 'partiallyResolved',
            'violatedAttributes' => [],
        ]),
        'api.printdeal.test/products/sku-tshirt' => Http::response([
            'statusCode' => 404, 'message' => 'Product not found',
        ], 404),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:product', ['sku' => 'sku-tshirt'])
        ->expectsOutputToContain('schema below comes from the validate')
        ->expectsOutputToContain('Gender Types')
        ->expectsOutputToContain('- Unisex')
        ->assertSuccessful();
});

it('explains a 404 instead of dumping a stack trace', function () {
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products/unknown-sku' => Http::response([
            'statusCode' => 404, 'message' => 'Product not found',
        ], 404),
        'api.printdeal.test/products/unknown-sku/validate' => Http::response([
            'statusCode' => 404, 'message' => 'Product not found',
        ], 404),
    ]);

    $this->artisan('printdeal:product', ['sku' => 'unknown-sku'])
        ->expectsOutputToContain('Printdeal does not know a product with sku unknown-sku')
        ->assertFailed();
});
