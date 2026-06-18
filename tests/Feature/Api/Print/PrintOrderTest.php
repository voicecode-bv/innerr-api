<?php

use App\Enums\MediaStatus;
use App\Enums\PrintOrderStatus;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Models\PrintOrder;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

function makePrintablePhoto(User $owner): array
{
    $post = Post::factory()->create(['user_id' => $owner->id]);
    $media = PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$owner->id}/posts/photo.jpg",
        'type' => 'image',
        'format' => 'jpg',
        'status' => MediaStatus::Ready,
    ]);

    return [$post, $media];
}

function shippingAddress(): array
{
    return [
        'firstName' => 'Michael',
        'lastName' => 'Blijleven',
        'street' => 'Hoofdstraat',
        'houseNumber' => '1',
        'postalCode' => '1234AB',
        'city' => 'Amsterdam',
        'country' => 'NL',
    ];
}

function fakeMollieCheckout(string $expectedValue = '24.95'): void
{
    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_print123';
    $payment->_links = (object) ['checkout' => (object) ['href' => 'https://mollie.test/checkout/print']];

    $payments->shouldReceive('create')
        ->once()
        ->withArgs(function (array $args) use ($expectedValue): bool {
            return $args['amount']['value'] === $expectedValue
                && $args['metadata']['kind'] === 'print_order';
        })
        ->andReturn($payment);

    app()->instance(MollieApiClient::class, $client);
}

/**
 * Mollie fake that records the payload passed to payments->create, so a test
 * can assert which fields were sent.
 */
function fakeMollieCapture(?array &$captured): void
{
    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_print123';
    $payment->_links = (object) ['checkout' => (object) ['href' => 'https://mollie.test/checkout/print']];

    $payments->shouldReceive('create')
        ->once()
        ->andReturnUsing(function (array $args) use (&$captured, $payment): Payment {
            $captured = $args;

            return $payment;
        });

    app()->instance(MollieApiClient::class, $client);
}

beforeEach(function () {
    $this->albumOffering = PrintdealProduct::factory()->offered('album', 2495)->create();
    $this->tshirtOffering = PrintdealProduct::factory()->offered('tshirt', 1995)->create();
});

it('creates a multi-item order with one Mollie checkout for the total', function () {
    $user = User::factory()->create();
    [$albumPost, $albumMedia] = makePrintablePhoto($user);
    [$tshirtPost, $tshirtMedia] = makePrintablePhoto($user);

    fakeMollieCheckout('44.90');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/print/orders', [
        'items' => [
            [
                'offering_id' => $this->albumOffering->id,
                'photos' => [['post_id' => $albumPost->id, 'media_id' => $albumMedia->id]],
            ],
            [
                'offering_id' => $this->tshirtOffering->id,
                'photos' => [['post_id' => $tshirtPost->id, 'media_id' => $tshirtMedia->id]],
                'options' => ['Size' => 'M'],
            ],
        ],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertCreated()
        ->assertJsonPath('checkout_url', 'https://mollie.test/checkout/print')
        ->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.amount_minor', 4490)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.1.options.Size', 'M');

    $order = PrintOrder::query()->with('items')->findOrFail($response->json('data.id'));

    expect($order->mollie_payment_id)->toBe('tr_print123')
        ->and($order->status)->toBe(PrintOrderStatus::PendingPayment)
        ->and($order->items)->toHaveCount(2)
        // Snapshot from the offering, so later admin edits can't change it.
        ->and($order->items[0]->printdeal_sku)->toBe($this->albumOffering->sku)
        ->and($order->items[0]->printdeal_attributes)->toBe($this->albumOffering->order_attributes)
        ->and($order->items[0]->photos[0]['path'])->toBe($albumMedia->path)
        ->and($order->items[1]->amount_minor)->toBe(1995);
});

it('assigns a sequential human-friendly order number alongside the uuid', function () {
    $user = User::factory()->create();
    [$post1, $media1] = makePrintablePhoto($user);
    [$post2, $media2] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    fakeMollieCheckout('24.95');
    $first = $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post1->id, 'media_id' => $media1->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    fakeMollieCheckout('24.95');
    $second = $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post2->id, 'media_id' => $media2->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    $firstNumber = $first->json('data.number');
    $secondNumber = $second->json('data.number');

    // Sequence-backed, so deterministic values are unsafe; assert the shape.
    expect($firstNumber)->toBeInt()->toBeGreaterThanOrEqual(1001)
        ->and($secondNumber)->toBeGreaterThan($firstNumber)
        ->and($first->json('data.id'))->not->toBe($firstNumber);
});

it('accepts the same offering twice with different options and photos', function () {
    $user = User::factory()->create();
    [$post1, $media1] = makePrintablePhoto($user);
    [$post2, $media2] = makePrintablePhoto($user);

    // Two t-shirts in different sizes: 2 x 1995.
    fakeMollieCheckout('39.90');
    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [
            [
                'offering_id' => $this->tshirtOffering->id,
                'photos' => [['post_id' => $post1->id, 'media_id' => $media1->id]],
                'options' => ['Size' => 'M'],
            ],
            [
                'offering_id' => $this->tshirtOffering->id,
                'photos' => [['post_id' => $post2->id, 'media_id' => $media2->id]],
                'options' => ['Size' => 'XL'],
            ],
        ],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertCreated()
        ->assertJsonPath('data.amount_minor', 3990)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.options.Size', 'M')
        ->assertJsonPath('data.items.1.options.Size', 'XL');
});

it('prices margin-based items live for their chosen options', function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);

    $puzzle = PrintdealProduct::factory()->offered('puzzle')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 10,
        'purchase_price_minor' => 2220,
        'user_options' => [
            ['attribute' => 'Print Area', 'values' => ['28 x 19 cm (35 pcs)', '68 x 44 cm (1000 pcs)']],
        ],
    ]);

    Http::fake([
        'api.printdeal.test/products/*' => Http::response(['price' => 30.0]),
    ]);

    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    // ceil(3000 * 1.10 * 1.21 VAT) = 3993, not the 2442 the synced base price
    // would give. The net+margin is 3300; VAT grosses it to the consumer price.
    fakeMollieCheckout('39.93');
    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $puzzle->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
            'options' => ['Print Area' => '68 x 44 cm (1000 pcs)'],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertCreated()
        ->assertJsonPath('data.amount_minor', 3993)
        ->assertJsonPath('data.items.0.amount_minor', 3993);
});

it('snapshots the artwork dimensions for the chosen size', function () {
    $puzzle = PrintdealProduct::factory()->offered('puzzle', 2495)->create([
        'user_options' => [
            ['attribute' => 'Formaat', 'values' => ['90 x 60 cm', '50 x 70 cm']],
        ],
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [
                ['value' => '90 x 60 cm', 'width' => 906, 'height' => 606],
                ['value' => '50 x 70 cm', 'width' => 506, 'height' => 706],
            ],
        ],
    ]);

    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    fakeMollieCheckout('24.95');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $puzzle->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
            'options' => ['Formaat' => '90 x 60 cm'],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    $order = PrintOrder::query()->with('items')->findOrFail($response->json('data.id'));

    expect($order->items[0]->artwork_width_mm)->toBe(906)
        ->and($order->items[0]->artwork_height_mm)->toBe(606);
});

it('refuses a size-configured product when the chosen options do not resolve to a size', function () {
    $puzzle = PrintdealProduct::factory()->offered('puzzle', 2495)->create([
        'user_options' => [
            ['attribute' => 'Formaat', 'values' => ['90 x 60 cm', '50 x 70 cm']],
        ],
        'artwork' => [
            'size_attribute' => 'Formaat',
            // '50 x 70 cm' is a valid option but has no size row: a
            // misconfiguration that must not silently print the fallback box.
            'sizes' => [
                ['value' => '90 x 60 cm', 'width' => 906, 'height' => 606],
            ],
        ],
    ]);

    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $puzzle->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
            'options' => ['Formaat' => '50 x 70 cm'],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'product_unavailable');

    // Refused before any order or payment: nothing persisted.
    expect(PrintOrder::query()->count())->toBe(0);
});

it('snapshots the full-resolution original path, not the display rendition', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    $media = PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$user->id}/posts/photo.jpg",
        'original_path' => "users/{$user->id}/originals/posts/photo.jpg",
        'type' => 'image',
        'format' => 'jpg',
        'status' => MediaStatus::Ready,
        'width' => 4000,
        'height' => 3000,
    ]);

    fakeMollieCheckout('24.95');
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    $order = PrintOrder::query()->with('items')->findOrFail($response->json('data.id'));

    expect($order->items[0]->photos[0]['path'])->toBe($media->original_path)
        ->and($order->items[0]->photos[0]['width'])->toBe(4000)
        ->and($order->items[0]->photos[0]['height'])->toBe(3000);
});

it('refuses a photo whose resolution is too low for the chosen size', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    // 200x200 px on the album's ~216 mm page is roughly 23 DPI — far below the
    // 150 DPI floor, so checkout must refuse before charging.
    $media = PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$user->id}/posts/tiny.jpg",
        'original_path' => "users/{$user->id}/originals/posts/tiny.jpg",
        'type' => 'image',
        'format' => 'jpg',
        'status' => MediaStatus::Ready,
        'width' => 200,
        'height' => 200,
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items');

    expect(PrintOrder::query()->count())->toBe(0);
});

it('allows a photo that meets the resolution floor for the chosen size', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    $media = PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$user->id}/posts/big.jpg",
        'original_path' => "users/{$user->id}/originals/posts/big.jpg",
        'type' => 'image',
        'format' => 'jpg',
        'status' => MediaStatus::Ready,
        'width' => 4000,
        'height' => 3000,
    ]);

    fakeMollieCheckout('24.95');
    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();
});

it('allows printing a circle member photo the user can view', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $owner->id]);
    $circle->members()->attach($viewer);

    [$post, $media] = makePrintablePhoto($owner);
    $post->circles()->attach($circle);

    fakeMollieCheckout();
    Sanctum::actingAs($viewer);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();
});

it('rejects photos from posts the user cannot view', function () {
    $stranger = User::factory()->create();
    [$post, $media] = makePrintablePhoto($stranger);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('items');

    expect(PrintOrder::query()->count())->toBe(0);
});

it('validates user options against the offering', function () {
    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    $payload = fn (array $options) => [
        'items' => [[
            'offering_id' => $this->tshirtOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
            'options' => $options,
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ];

    // Missing size.
    $this->postJson('/api/print/orders', $payload([]))
        ->assertJsonValidationErrors('items.0.options.Size');

    // Size outside the allowed values.
    $this->postJson('/api/print/orders', $payload(['Size' => 'XXXL']))
        ->assertJsonValidationErrors('items.0.options.Size');

    // Option the offering doesn't know.
    $this->postJson('/api/print/orders', $payload(['Size' => 'M', 'Color' => 'Red']))
        ->assertJsonValidationErrors('items.0.options.Color');
});

it('enforces the photo limits of each app product', function () {
    $user = User::factory()->create();
    [$post1, $media1] = makePrintablePhoto($user);
    [$post2, $media2] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    // A t-shirt takes exactly one photo.
    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->tshirtOffering->id,
            'photos' => [
                ['post_id' => $post1->id, 'media_id' => $media1->id],
                ['post_id' => $post2->id, 'media_id' => $media2->id],
            ],
            'options' => ['Size' => 'M'],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertJsonValidationErrors('items.0.photos');
});

it('refuses orders with an offering that is not orderable', function () {
    $this->albumOffering->update(['enabled' => false]);

    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'product_unavailable');
});

it('lists only the user own orders with their items and hides others', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = PrintOrder::factory()->withItem()->for($user)->create();
    $theirs = PrintOrder::factory()->withItem()->for($other)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/print/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonCount(1, 'data.0.items');

    $this->getJson("/api/print/orders/{$mine->id}")->assertOk();
    $this->getJson("/api/print/orders/{$theirs->id}")->assertNotFound();
});

it('saves the shipping address on the user when opted in', function () {
    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    fakeMollieCheckout('24.95');
    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'save_address' => true,
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    expect($user->refresh()->shipping_address)->toBe(shippingAddress());
});

it('does not save the shipping address without opt-in', function () {
    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    fakeMollieCheckout('24.95');
    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'items' => [[
            'offering_id' => $this->albumOffering->id,
            'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        ]],
        'shipping_address' => shippingAddress(),
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();

    expect($user->refresh()->shipping_address)->toBeNull();
});
