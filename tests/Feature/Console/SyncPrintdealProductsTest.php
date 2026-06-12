<?php

use App\Models\PrintdealProduct;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);
});

function fakeCatalog(array $products, array $prices = [], array $details = ['attributes' => []]): void
{
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products?*' => Http::response([
            'total' => count($products),
            'results' => $products,
        ]),
        // Order matters: the price pattern must win from the generic
        // product-details pattern below it.
        'api.printdeal.test/products/*/prices' => Http::response($prices),
        'api.printdeal.test/products/*' => Http::response($details),
    ]);
    Http::preventStrayRequests();
}

it('mirrors the catalog and flags delisted products', function () {
    $stillListed = PrintdealProduct::factory()->create(['sku' => 'sku-1']);
    $gone = PrintdealProduct::factory()->create(['sku' => 'sku-gone']);

    fakeCatalog([
        ['sku' => 'sku-1', 'name' => ['en-EN' => 'Posters', 'nl-NL' => 'Posters']],
        ['sku' => 'sku-new', 'name' => ['en-EN' => 'Mugs', 'nl-NL' => 'Mokken']],
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect(PrintdealProduct::query()->count())->toBe(3)
        ->and($stillListed->fresh()->delisted_at)->toBeNull()
        ->and($stillListed->fresh()->name['nl-NL'])->toBe('Posters')
        ->and($gone->fresh()->delisted_at)->not->toBeNull()
        ->and(PrintdealProduct::query()->where('sku', 'sku-new')->first()->name['nl-NL'])->toBe('Mokken');
});

it('re-lists a product that returns to the catalog', function () {
    $product = PrintdealProduct::factory()->delisted()->create(['sku' => 'sku-1']);

    fakeCatalog([
        ['sku' => 'sku-1', 'name' => ['en-EN' => 'Posters']],
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($product->fresh()->delisted_at)->toBeNull();
});

it('refreshes purchase prices for offered products', function () {
    $offered = PrintdealProduct::factory()->offered('album')->create(['sku' => 'sku-album']);
    $notOffered = PrintdealProduct::factory()->create(['sku' => 'sku-idle']);

    fakeCatalog(
        [
            ['sku' => 'sku-album', 'name' => ['en-EN' => 'Albums']],
            ['sku' => 'sku-idle', 'name' => ['en-EN' => 'Idle']],
        ],
        [
            'prices' => [[
                'quantity' => 1,
                'price' => ['currency' => 'EUR', 'netAmount' => 8.26, 'grossAmount' => 10.0, 'vatPercentage' => 21],
            ]],
        ],
    );

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($offered->fresh()->purchase_price_minor)->toBe(1000)
        ->and($notOffered->fresh()->purchase_price_minor)->toBeNull();

    // Only the offered product was inspected and priced.
    Http::assertSentCount(4); // login + products page + schema + price call
});

it('stores attribute schemas for mapped products', function () {
    // Mapped but not yet enabled: exactly the moment the admin needs the
    // schema to fill in the order attributes.
    $mapped = PrintdealProduct::factory()->create([
        'sku' => 'sku-tshirt',
        'app_product' => 'tshirt',
        'enabled' => false,
    ]);

    fakeCatalog(
        [['sku' => 'sku-tshirt', 'name' => ['en-EN' => 'Basic T-shirt']]],
        details: [
            'attributes' => [
                [
                    'name' => 'Gender Types',
                    'values' => [['name' => 'Unisex'], ['name' => 'Men']],
                ],
                [
                    'name' => 'Size',
                    'values' => [['name' => 'S'], ['name' => 'M']],
                ],
            ],
        ],
    );

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($mapped->fresh()->attribute_schema)->toBe([
        ['attribute' => 'Gender Types', 'values' => ['Unisex', 'Men']],
        ['attribute' => 'Size', 'values' => ['S', 'M']],
    ]);
});

it('falls back to the validate endpoint for schemas when details return 404', function () {
    $mapped = PrintdealProduct::factory()->create([
        'sku' => 'sku-tshirt',
        'app_product' => 'tshirt',
    ]);

    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products?*' => Http::response([
            'results' => [['sku' => 'sku-tshirt', 'name' => ['en-EN' => 'Basic T-shirt']]],
        ]),
        'api.printdeal.test/products/sku-tshirt/validate' => Http::response([
            'remainingOptions' => [
                ['attribute' => 'Gender Types', 'values' => ['Unisex']],
            ],
            'selectionStatus' => 'partiallyResolved',
            'violatedAttributes' => [],
        ]),
        'api.printdeal.test/products/sku-tshirt' => Http::response(
            ['statusCode' => 404, 'message' => 'Product not found'],
            404,
        ),
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($mapped->fresh()->attribute_schema)->toBe([
        ['attribute' => 'Gender Types', 'values' => ['Unisex']],
    ]);
});

it('keeps syncing when one price request fails', function () {
    PrintdealProduct::factory()->offered('album')->create(['sku' => 'sku-album']);

    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/products?*' => Http::response([
            'results' => [['sku' => 'sku-album', 'name' => ['en-EN' => 'Albums']]],
        ]),
        'api.printdeal.test/products/*/prices' => Http::response(['message' => 'nope'], 500),
        'api.printdeal.test/products/*' => Http::response(['attributes' => []]),
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect(PrintdealProduct::query()->where('sku', 'sku-album')->first()->purchase_price_minor)->toBeNull();
});
