<?php

use App\Filament\Resources\PrintdealProducts\Pages\EditPrintdealProduct;
use App\Filament\Resources\PrintdealProducts\Pages\ListPrintdealProducts;
use App\Models\PrintdealProduct;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);
});

it('lists synced products', function () {
    $product = PrintdealProduct::factory()->create([
        'name' => ['nl-NL' => 'Mokken', 'en-EN' => 'Mugs'],
    ]);

    Livewire::test(ListPrintdealProducts::class)
        ->assertSuccessful()
        ->assertSee('Mokken')
        ->assertSee($product->sku);
});

it('searches products by name and sku, case-insensitively', function () {
    $mugs = PrintdealProduct::factory()->create([
        'sku' => 'sku-mugs-uuid',
        'name' => ['en-EN' => 'mugs'],
    ]);
    $posters = PrintdealProduct::factory()->create([
        'sku' => 'sku-posters-uuid',
        'name' => ['en-EN' => 'posters'],
    ]);

    Livewire::test(ListPrintdealProducts::class)
        ->searchTable('Mugs')
        ->assertCanSeeTableRecords([$mugs])
        ->assertCanNotSeeTableRecords([$posters])
        ->searchTable('SKU-POSTERS')
        ->assertCanSeeTableRecords([$posters])
        ->assertCanNotSeeTableRecords([$mugs]);
});

it('suggests schema attributes and values inside the repeater fields', function () {
    // Schema present: no fetch happens, and the repeater fields must offer
    // the names/values of the page's product (inside a repeater $record is
    // the item record, not the product, which used to leave these empty).
    $product = PrintdealProduct::factory()->create([
        'attribute_schema' => [
            ['attribute' => 'Packing', 'values' => ['Sealed On Cardboard', 'Box With Printed Sleeve']],
            ['attribute' => 'quantity', 'values' => ['1', '2']],
        ],
        'order_attributes' => [
            ['attribute' => 'Packing', 'value' => 'Sealed On Cardboard'],
        ],
    ]);

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->assertSuccessful()
        // Attribute-name suggestion plus the allowed values of 'Packing'.
        ->assertSee('Box With Printed Sleeve')
        // The system-managed quantity attribute is never suggested.
        ->assertDontSee('value="quantity"', false);
});

it('persists an edited order attribute value', function () {
    Http::fake(['api.printdeal.test/products/*' => Http::response(['price' => 10.0])]);

    $product = PrintdealProduct::factory()->offered('puzzle')->create([
        'attribute_schema' => [
            ['attribute' => 'Packing', 'values' => ['Sealed On Cardboard', 'Box With Printed Sleeve']],
        ],
        'order_attributes' => [
            ['attribute' => 'Packing', 'value' => 'Sealed On Cardboard'],
        ],
        'user_options' => null,
    ]);

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->fillForm([
            'order_attributes' => [
                ['attribute' => 'Packing', 'value' => 'Box With Printed Sleeve'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->order_attributes)->toBe([
        ['attribute' => 'Packing', 'value' => 'Box With Printed Sleeve'],
    ]);
});

it('drops order attributes that are also user options on save', function () {
    Http::fake([
        // The post-save price refresh; its outcome is irrelevant here.
        'api.printdeal.test/products/*' => Http::response(['price' => 10.0]),
    ]);

    $product = PrintdealProduct::factory()->create([
        'attribute_schema' => [
            ['attribute' => 'Printing Process', 'values' => ['Sublimation']],
            ['attribute' => 'Print Area', 'values' => ['28 x 19 cm (35 pcs)', '54 x 40 cm (500 pcs)']],
        ],
    ]);

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->fillForm([
            'enabled' => true,
            'app_product' => 'puzzle',
            'order_attributes' => [
                ['attribute' => 'Printing Process', 'value' => 'Sublimation'],
                // Left over from the prefill, but moved to a customer choice.
                ['attribute' => 'Print Area', 'value' => '28 x 19 cm (35 pcs)'],
            ],
            'user_options' => [
                ['attribute' => 'Print Area', 'values' => ['28 x 19 cm (35 pcs)', '54 x 40 cm (500 pcs)']],
            ],
            'margin_percent' => 10,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->order_attributes)->toBe([
        ['attribute' => 'Printing Process', 'value' => 'Sublimation'],
    ]);
});

it('prefills the order attributes from the schema, completing single-value ones', function () {
    $product = PrintdealProduct::factory()->create([
        'attribute_schema' => [
            ['attribute' => 'Printing Process', 'values' => ['Sublimation']],
            ['attribute' => 'Print Area', 'values' => ['25 x 17.5 cm (96 pcs)', '54 x 40 cm (500 pcs)']],
            ['attribute' => 'quantity', 'values' => ['1', '2']],
        ],
    ]);

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        // The repeater keys its rows by generated uuid; compare values only.
        ->assertSet('data.order_attributes', fn (array $state): bool => array_values($state) === [
            // Only one allowed value: chosen automatically.
            ['attribute' => 'Printing Process', 'value' => 'Sublimation'],
            // A real choice: row ready, value left to the admin.
            ['attribute' => 'Print Area', 'value' => ''],
        ]);
});

it('leaves configured order attributes untouched when opening', function () {
    $product = PrintdealProduct::factory()->create([
        'attribute_schema' => [
            ['attribute' => 'Printing Process', 'values' => ['Sublimation']],
            ['attribute' => 'Packing', 'values' => ['Sealed On Cardboard', 'Box With Printed Sleeve']],
        ],
        'order_attributes' => [
            ['attribute' => 'Packing', 'value' => 'Box With Printed Sleeve'],
        ],
    ]);

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->assertSet('data.order_attributes', fn (array $state): bool => array_values($state) === [
            ['attribute' => 'Packing', 'value' => 'Box With Printed Sleeve'],
        ]);
});

it('fetches the attribute schema from the API when opening an unsynced product', function () {
    $product = PrintdealProduct::factory()->create(['sku' => 'sku-mugs']);

    Http::fake([
        'api.printdeal.test/products/sku-mugs/attributes' => Http::response([
            'format' => ['A4', 'A5'],
            'externals' => ['width' => []],
        ]),
    ]);
    Http::preventStrayRequests();

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->assertSuccessful();

    expect($product->fresh()->attribute_schema)->toBe([
        ['attribute' => 'format', 'values' => ['A4', 'A5']],
    ]);
});

it('keeps the edit page usable when the schema fetch fails', function () {
    $product = PrintdealProduct::factory()->create();

    Http::fake([
        'api.printdeal.test/products/*/attributes' => Http::response(['message' => 'not found'], 404),
    ]);
    Http::preventStrayRequests();

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->assertSuccessful()
        ->assertNotified();

    expect($product->fresh()->attribute_schema)->toBeNull();
});

it('configures an offering through the edit page and refreshes the purchase price', function () {
    $product = PrintdealProduct::factory()->create(['sku' => 'sku-tshirt']);

    Http::fake([
        // Order matters: the generic products/* pattern below would also
        // match the attributes path.
        'api.printdeal.test/products/sku-tshirt/attributes' => Http::response([
            'Style' => ['Basic'],
            'Size' => ['S', 'M'],
        ]),
        // POST /products/{sku}: validate the selection and price one piece.
        'api.printdeal.test/products/sku-tshirt' => Http::response([
            'price' => 12.5,
            'promisedArrivalDate' => '2026-07-01',
        ]),
    ]);
    Http::preventStrayRequests();

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->fillForm([
            'enabled' => true,
            'app_product' => 'tshirt',
            'order_attributes' => [
                ['attribute' => 'Style', 'value' => 'Basic'],
            ],
            'user_options' => [
                ['attribute' => 'Size', 'values' => ['S', 'M']],
            ],
            'margin_percent' => 40,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();

    expect($product->enabled)->toBeTrue()
        ->and($product->app_product)->toBe('tshirt')
        ->and($product->order_attributes)->toBe([
            ['attribute' => 'Style', 'value' => 'Basic'],
        ])
        ->and($product->user_options)->toBe([
            ['attribute' => 'Size', 'values' => ['S', 'M']],
        ])
        ->and($product->margin_percent)->toBe(40.0)
        ->and($product->purchase_price_minor)->toBe(1250);

    // The price call mirrors an order line: configured attributes plus the
    // first value of every user option and a single piece as the quantity.
    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST') {
            return true;
        }

        $attributes = collect($request['attributes'])->keyBy('attribute');

        return $attributes['Style']['value'] === 'Basic'
            && $attributes['Size']['value'] === 'S'
            && $attributes['quantity']['value'] === '1';
    });
});
