<?php

use App\Enums\MediaStatus;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * A ready image with the given pixel dimensions on a post owned by $owner.
 */
function makePrintMedia(User $owner, int $width, int $height): PostMedia
{
    $post = Post::factory()->create(['user_id' => $owner->id]);

    return PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$owner->id}/posts/photo.jpg",
        'original_path' => "users/{$owner->id}/originals/posts/photo.jpg",
        'type' => 'image',
        'format' => 'jpg',
        'status' => MediaStatus::Ready,
        'width' => $width,
        'height' => $height,
    ]);
}

/** Canvas offering with two selectable sizes, one too large for a normal photo. */
function canvasWithSizes(): PrintdealProduct
{
    return PrintdealProduct::factory()->offered('canvas', 3995)->create([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [
                ['value' => '30 x 20 cm', 'width' => 300, 'height' => 200],
                ['value' => '120 x 80 cm', 'width' => 1200, 'height' => 800],
            ],
            'frame_attribute' => null,
            'frames' => [],
        ],
    ]);
}

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

it('includes the admin-configured artwork sizing so the app can size the PDF', function () {
    $artwork = [
        'size_attribute' => 'Formaat',
        'sizes' => [
            ['value' => '90 x 60 cm', 'width' => 906, 'height' => 606],
        ],
        'frame_attribute' => null,
        'frames' => [],
    ];

    $puzzle = PrintdealProduct::factory()->offered('puzzle', 3495)->create([
        'artwork' => $artwork,
    ]);
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs(User::factory()->create());

    $data = collect($this->getJson('/api/print/products')->json('data'));

    expect($data->firstWhere('id', $puzzle->id)['artwork'])->toBe($artwork)
        ->and($data->firstWhere('id', $album->id)['artwork'])->toBeNull();
});

it('carries no printability hint when no photo is named', function () {
    $canvas = canvasWithSizes();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/print/products')->assertOk();

    expect(collect($response->json('data'))->firstWhere('id', $canvas->id)['printability'])->toBeNull()
        ->and($response->json('min_dpi'))->toBe(150);
});

it('marks each size printable or not for the named photo at the quality floor', function () {
    $user = User::factory()->create();
    // 4000x3000 fills the 30x20 cm canvas at ~330 DPI (printable) but only
    // ~84 DPI on the 120x80 cm canvas — below the 150 DPI floor.
    $media = makePrintMedia($user, 4000, 3000);
    $canvas = canvasWithSizes();

    Sanctum::actingAs($user);

    $printability = collect(
        $this->getJson("/api/print/products?media_id={$media->id}")->json('data')
    )->firstWhere('id', $canvas->id)['printability'];

    expect($printability['printable'])->toBeTrue()
        ->and($printability['sizes'])->toHaveCount(2)
        ->and(collect($printability['sizes'])->firstWhere('value', '30 x 20 cm')['printable'])->toBeTrue()
        ->and(collect($printability['sizes'])->firstWhere('value', '120 x 80 cm')['printable'])->toBeFalse();
});

it('reports a fixed-size product unprintable when the photo is too small', function () {
    $user = User::factory()->create();
    // 200x200 on the album's ~216 mm page is ~23 DPI, far below the floor.
    $media = makePrintMedia($user, 200, 200);
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs($user);

    $printability = collect(
        $this->getJson("/api/print/products?media_id={$media->id}")->json('data')
    )->firstWhere('id', $album->id)['printability'];

    // No artwork sizing: a single fixed-size verdict with a null size value.
    expect($printability['printable'])->toBeFalse()
        ->and($printability['sizes'])->toBe([
            ['value' => null, 'effective_dpi' => 24, 'printable' => false],
        ]);
});

it('hints printability for a circle member photo the user can view', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $owner->id]);
    $circle->members()->attach($viewer);

    $media = makePrintMedia($owner, 4000, 3000);
    $media->post->circles()->attach($circle);
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs($viewer);

    $printability = collect(
        $this->getJson("/api/print/products?media_id={$media->id}")->json('data')
    )->firstWhere('id', $album->id)['printability'];

    expect($printability['printable'])->toBeTrue();
});

it('omits printability for a photo the user cannot view', function () {
    $owner = User::factory()->create();
    $media = makePrintMedia($owner, 4000, 3000);
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs(User::factory()->create());

    $printability = collect(
        $this->getJson("/api/print/products?media_id={$media->id}")->json('data')
    )->firstWhere('id', $album->id)['printability'];

    expect($printability)->toBeNull();
});

it('ignores a malformed media_id without failing the catalog', function () {
    $album = PrintdealProduct::factory()->offered('album', 2495)->create();

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/print/products?media_id=not-a-uuid')->assertOk();

    expect(collect($response->json('data'))->firstWhere('id', $album->id)['printability'])->toBeNull();
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

it('computes margin-based prices from the synced purchase price, incl. VAT', function () {
    $offering = PrintdealProduct::factory()->offered('album')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 30,
        'purchase_price_minor' => 1000,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $album = collect($this->getJson('/api/print/products')->json('data'))
        ->firstWhere('id', $offering->id);

    // 1000 net * 1.30 margin = 1300, grossed up by 21% VAT = 1573.
    expect($album['price_minor'])->toBe(1573)
        ->and($album['available'])->toBeTrue();
});

it('grosses the margin price up by VAT so the order covers the incl-VAT invoice', function () {
    // The real-world case: Printdeal quotes EUR 70.50 net, we add 15% margin
    // and 21% VAT, so the customer pays EUR 98.11 — above the EUR 85.30 the
    // incl-VAT invoice costs, instead of the loss-making EUR 81.08.
    $offering = PrintdealProduct::factory()->offered('canvas')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 15,
        'purchase_price_minor' => 7050,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $canvas = collect($this->getJson('/api/print/products')->json('data'))
        ->firstWhere('id', $offering->id);

    expect($canvas['price_minor'])->toBe(9811);
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
