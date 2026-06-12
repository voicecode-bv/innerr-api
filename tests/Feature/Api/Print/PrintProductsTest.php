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
    // In the catalog but not offered: hidden entirely.
    PrintdealProduct::factory()->create();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/print/products')->assertOk();
    $data = collect($response->json('data'));

    expect($data)->toHaveCount(3)
        ->and($data->where('app_product', 'tshirt'))->toHaveCount(2)
        ->and($data->firstWhere('id', $premiumTee->id))->toMatchArray([
            'price_minor' => 2995,
            'available' => true,
            'max_photos' => 1,
        ])
        ->and($data->firstWhere('id', $basicTee->id)['user_options'][0]['attribute'])->toBe('Size')
        ->and($data->firstWhere('id', $album->id)['user_options'])->toBe([])
        ->and($response->json('shipping_countries'))->toBe(['NL', 'BE'])
        ->and($response->json('return_url'))->toBeString();
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
