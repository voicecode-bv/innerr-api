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
        'api.printdeal.test/products/sku-tshirt/attributes' => Http::response([
            'Gender Types' => ['Unisex', 'Men'],
            'width' => ['minimum' => 100, 'maximum' => 500, 'increment' => 1, 'unitOfMeasure' => 'mm'],
            'externals' => ['width' => [['validation' => 'minimum', 'value' => 100]]],
        ]),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:product', ['sku' => 'sku-tshirt'])
        ->expectsOutputToContain('sku-tshirt')
        ->expectsOutputToContain('Gender Types')
        ->expectsOutputToContain('- Unisex')
        ->expectsOutputToContain('range 100 to 500, steps of 1 mm')
        ->expectsOutputToContain('Free-input attributes')
        ->assertSuccessful();
});

it('resolves a local printdeal_products id to its sku', function () {
    $local = PrintdealProduct::factory()->create(['sku' => 'real-sku-uuid']);

    Http::fake([
        'api.printdeal.test/products/real-sku-uuid/attributes' => Http::response([
            'Gender Types' => ['Unisex'],
        ]),
    ]);
    Http::preventStrayRequests();

    $this->artisan('printdeal:product', ['sku' => $local->id])
        ->expectsOutputToContain('Resolved local product id to sku real-sku-uuid.')
        ->expectsOutputToContain('Gender Types')
        ->assertSuccessful();
});

it('explains a 404 instead of dumping a stack trace', function () {
    Http::fake([
        'api.printdeal.test/products/unknown-sku/attributes' => Http::response([
            'statusCode' => 404, 'message' => 'Product not found',
        ], 404),
    ]);

    $this->artisan('printdeal:product', ['sku' => 'unknown-sku'])
        ->expectsOutputToContain('Printdeal does not know a product with sku unknown-sku')
        ->assertFailed();
});
