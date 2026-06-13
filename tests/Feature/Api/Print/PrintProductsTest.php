<?php

use App\Models\PrintdealProduct;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/print/products')->assertUnauthorized();
});

it('lists every enabled offering, including multiple per app product', function () {
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();
    $basicTee = PrintdealProduct::factory()->offered('tshirt', 1995)->create([
        'name' => ['en-EN' => 'Basic T-shirt', 'nl-NL' => 'Basic T-shirt'],
    ]);
    $premiumTee = PrintdealProduct::factory()->offered('tshirt', 2995)->create([
        'name' => ['en-EN' => 'Premium T-shirt', 'nl-NL' => 'Premium T-shirt'],
    ]);
    $puzzle = PrintdealProduct::factory()->offered('puzzle', 3495)->create([
        'name' => ['en-EN' => 'Photo puzzle 500 pieces'],
    ]);
    $canvas = PrintdealProduct::factory()->offered('canvas', 3995)->create([
        'name' => ['en-EN' => 'Photo canvas 40x30'],
    ]);
    // In the catalog but not offered: hidden entirely.
    PrintdealProduct::factory()->create();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/print/products')->assertOk();
    $data = collect($response->json('data'));

    expect($data)->toHaveCount(5)
        ->and($data->where('app_product', 'tshirt'))->toHaveCount(2)
        ->and($data->firstWhere('id', $premiumTee->id))->toMatchArray([
            'price_minor' => 2995,
            'available' => true,
            'max_photos' => 1,
        ])
        ->and($data->firstWhere('id', $puzzle->id))->toMatchArray([
            'app_product' => 'puzzle',
            'price_minor' => 3495,
            'available' => true,
            'min_photos' => 1,
            'max_photos' => 1,
        ])
        ->and($data->firstWhere('id', $canvas->id))->toMatchArray([
            'app_product' => 'canvas',
            'price_minor' => 3995,
            'available' => true,
            'max_photos' => 1,
        ])
        ->and($data->firstWhere('id', $basicTee->id)['user_options'][0]['attribute'])->toBe('Size')
        ->and($data->firstWhere('id', $album->id)['user_options'])->toBe([])
        ->and($response->json('shipping_countries'))->toBe(['NL', 'BE'])
        ->and($response->json('return_url'))->toBeString();
});

it('includes the artwork format and orientation policy per product', function () {
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();
    $canvas = PrintdealProduct::factory()->offered('canvas', 3995)->create();

    Sanctum::actingAs(User::factory()->create());

    $data = collect($this->getJson('/api/print/products')->json('data'));

    expect($data->firstWhere('id', $album->id)['format'])->toBe([
        'width' => 210,
        'height' => 210,
        'orientation' => 'fixed',
    ])
        ->and($data->firstWhere('id', $canvas->id)['format'])->toBe([
            'width' => 400,
            'height' => 300,
            'orientation' => 'auto',
        ]);
});

it('returns the saved address for prefilling, or null when none is stored', function () {
    PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/print/products')
        ->assertOk()
        ->assertJsonPath('saved_address', null);

    $address = [
        'firstName' => 'Michael',
        'lastName' => 'Blijleven',
        'street' => 'Hoofdstraat',
        'houseNumber' => '1',
        'postalCode' => '1234AB',
        'city' => 'Amsterdam',
        'country' => 'NL',
    ];
    Sanctum::actingAs(User::factory()->create(['shipping_address' => $address]));
    $this->getJson('/api/print/products')
        ->assertOk()
        ->assertJsonPath('saved_address', $address);
});

it('treats offerings whose attributes are all user options as orderable', function () {
    $offering = PrintdealProduct::factory()->offered('puzzle')->create([
        'order_attributes' => null,
        'user_options' => [
            ['attribute' => 'Print Area', 'values' => ['28 x 19 cm (35 pcs)', '54 x 40 cm (500 pcs)']],
            ['attribute' => 'Packing', 'values' => ['Box With Printed Sleeve']],
        ],
    ]);

    Sanctum::actingAs(User::factory()->create());

    $puzzle = collect($this->getJson('/api/print/products')->json('data'))
        ->firstWhere('id', $offering->id);

    expect($puzzle['available'])->toBeTrue();
});

it('computes margin-based prices from the synced purchase price', function () {
    $offering = PrintdealProduct::factory()->offered('album')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 30,
        'purchase_price_minor' => 1000,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $album = collect($this->getJson('/api/print/products')->json('data'))
        ->firstWhere('id', $offering->id);

    expect($album['price_minor'])->toBe(1300)
        ->and($album['available'])->toBeTrue();
});

it('marks unpriced offerings unavailable and hides disabled or delisted ones', function () {
    PrintdealProduct::factory()->offered('album')->create(['enabled' => false]);
    PrintdealProduct::factory()->offered('mug')->delisted()->create();
    $unpriced = PrintdealProduct::factory()->offered('tshirt')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 30,
        'purchase_price_minor' => null,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $data = collect($this->getJson('/api/print/products')->json('data'));

    expect($data)->toHaveCount(1)
        ->and($data->first()['id'])->toBe($unpriced->id)
        ->and($data->first()['available'])->toBeFalse()
        ->and($data->first()['price_minor'])->toBeNull();
});
