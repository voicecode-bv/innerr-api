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

function fakeCatalog(array $categories, array $price = [], array $attributes = []): void
{
    Http::fake([
        // Order matters: the generic products/* pattern below would also
        // match these more specific paths.
        'api.printdeal.test/products/categories' => Http::response($categories),
        'api.printdeal.test/products/*/attributes' => Http::response($attributes),
        // POST /products/{sku}: validate a selection and retrieve its price.
        'api.printdeal.test/products/*' => Http::response($price),
    ]);
    Http::preventStrayRequests();
}

it('mirrors the catalog and flags delisted products', function () {
    $stillListed = PrintdealProduct::factory()->create(['sku' => 'sku-1']);
    $gone = PrintdealProduct::factory()->create(['sku' => 'sku-gone']);

    fakeCatalog([
        ['sku' => 'sku-1', 'name' => 'posters', 'combinationsModifiedAt' => '2026-01-01 07:00:00'],
        ['sku' => 'sku-new', 'name' => 'mugs', 'combinationsModifiedAt' => '2026-01-01 07:00:00'],
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect(PrintdealProduct::query()->count())->toBe(3)
        ->and($stillListed->fresh()->delisted_at)->toBeNull()
        ->and($stillListed->fresh()->name['en-EN'])->toBe('posters')
        ->and($gone->fresh()->delisted_at)->not->toBeNull()
        ->and(PrintdealProduct::query()->where('sku', 'sku-new')->first()->name['en-EN'])->toBe('mugs');
});

it('re-lists a product that returns to the catalog', function () {
    $product = PrintdealProduct::factory()->delisted()->create(['sku' => 'sku-1']);

    fakeCatalog([
        ['sku' => 'sku-1', 'name' => 'posters'],
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($product->fresh()->delisted_at)->toBeNull();
});

it('refreshes purchase prices for offered products', function () {
    $offered = PrintdealProduct::factory()->offered('album')->create(['sku' => 'sku-album']);
    $notOffered = PrintdealProduct::factory()->create(['sku' => 'sku-idle']);

    fakeCatalog(
        [
            ['sku' => 'sku-album', 'name' => 'albums'],
            ['sku' => 'sku-idle', 'name' => 'idle'],
        ],
        price: ['price' => 10.0, 'promisedArrivalDate' => '2026-07-01'],
    );

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($offered->fresh()->purchase_price_minor)->toBe(1000)
        ->and($notOffered->fresh()->purchase_price_minor)->toBeNull();

    // Only the offered product was inspected and priced.
    Http::assertSentCount(3); // categories + schema + price call

    // The price call mirrors an order line: configured attributes plus a
    // single piece as the quantity attribute.
    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST') {
            return true;
        }

        $attributes = collect($request['attributes'])->keyBy('attribute');

        return str_ends_with($request->url(), '/products/sku-album')
            && $attributes['Format']['value'] === 'A4'
            && $attributes['quantity']['value'] === '1';
    });
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
        [['sku' => 'sku-tshirt', 'name' => 't-shirts']],
        attributes: [
            'Gender Types' => ['Unisex', 'Men'],
            'Size' => ['S', 'M'],
            // Range attributes have no enumerable values.
            'width' => ['minimum' => 100, 'maximum' => 500, 'increment' => 1, 'unitOfMeasure' => 'mm'],
            // Validation rules for free-input attributes, not an attribute.
            'externals' => ['width' => [['validation' => 'minimum', 'value' => 100]]],
        ],
    );

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect($mapped->fresh()->attribute_schema)->toBe([
        ['attribute' => 'Gender Types', 'values' => ['Unisex', 'Men']],
        ['attribute' => 'Size', 'values' => ['S', 'M']],
        ['attribute' => 'width', 'values' => []],
    ]);
});

it('keeps syncing when one price request fails', function () {
    PrintdealProduct::factory()->offered('album')->create(['sku' => 'sku-album']);

    Http::fake([
        'api.printdeal.test/products/categories' => Http::response([
            ['sku' => 'sku-album', 'name' => 'albums'],
        ]),
        'api.printdeal.test/products/*/attributes' => Http::response([]),
        'api.printdeal.test/products/*' => Http::response(['message' => 'nope'], 500),
    ]);

    $this->artisan('printdeal:sync-products')->assertSuccessful();

    expect(PrintdealProduct::query()->where('sku', 'sku-album')->first()->purchase_price_minor)->toBeNull();
});
