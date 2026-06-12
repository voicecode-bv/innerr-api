<?php

use App\Models\PrintdealProduct;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/print/products')->assertUnauthorized();
});

it('returns the catalog with prices and availability from the offerings', function () {
    PrintdealProduct::factory()->offered('album', 2495)->create();
    PrintdealProduct::factory()->offered('tshirt', 1995)->create();
    // Mug exists in the synced catalog but is not offered.
    PrintdealProduct::factory()->create();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/print/products')->assertOk();

    $album = collect($response->json('data'))->firstWhere('id', 'album');
    $mug = collect($response->json('data'))->firstWhere('id', 'mug');
    $tshirt = collect($response->json('data'))->firstWhere('id', 'tshirt');

    expect($album)->toMatchArray([
        'price_minor' => 2495,
        'currency' => 'EUR',
        'available' => true,
    ])
        ->and($mug['available'])->toBeFalse()
        ->and($mug['price_minor'])->toBeNull()
        ->and($mug['max_photos'])->toBe(1)
        ->and($tshirt['sizes'])->toContain('M')
        ->and($response->json('shipping_countries'))->toBe(['NL', 'BE'])
        ->and($response->json('return_url'))->toBeString();
});

it('computes margin-based prices from the synced purchase price', function () {
    PrintdealProduct::factory()->offered('album')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 30,
        'purchase_price_minor' => 1000,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $album = collect($this->getJson('/api/print/products')->json('data'))
        ->firstWhere('id', 'album');

    expect($album['price_minor'])->toBe(1300)
        ->and($album['available'])->toBeTrue();
});

it('hides offerings that are disabled, delisted, or unpriced', function () {
    PrintdealProduct::factory()->offered('album')->create(['enabled' => false]);
    PrintdealProduct::factory()->offered('mug')->delisted()->create();
    PrintdealProduct::factory()->offered('tshirt')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 30,
        'purchase_price_minor' => null,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $available = collect($this->getJson('/api/print/products')->json('data'))
        ->where('available', true)
        ->pluck('id');

    expect($available)->toBeEmpty();
});
