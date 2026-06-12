<?php

use App\Enums\MediaStatus;
use App\Enums\PrintOrderStatus;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Models\PrintOrder;
use App\Models\User;
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

function fakeMollieCheckout(): void
{
    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_print123';
    $payment->_links = (object) ['checkout' => (object) ['href' => 'https://mollie.test/checkout/print']];

    $payments->shouldReceive('create')
        ->once()
        ->withArgs(function (array $args): bool {
            return $args['amount']['value'] === '24.95'
                && $args['metadata']['kind'] === 'print_order'
                && str_contains($args['webhookUrl'], '/api/webhooks/print/mollie');
        })
        ->andReturn($payment);

    app()->instance(MollieApiClient::class, $client);
}

beforeEach(function () {
    $this->albumOffering = PrintdealProduct::factory()->offered('album', 2495)->create();
    PrintdealProduct::factory()->offered('tshirt', 1995)->create();
});

it('creates a pending order with a Mollie checkout for own photos', function () {
    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    fakeMollieCheckout();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/print/orders', [
        'product' => 'album',
        'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        'shipping_address' => [
            'firstName' => 'Michael',
            'lastName' => 'Blijleven',
            'street' => 'Hoofdstraat',
            'houseNumber' => '1',
            'postalCode' => '1234AB',
            'city' => 'Amsterdam',
            'country' => 'NL',
        ],
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertCreated()
        ->assertJsonPath('checkout_url', 'https://mollie.test/checkout/print')
        ->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.amount_minor', 2495);

    $order = PrintOrder::query()->findOrFail($response->json('data.id'));

    expect($order->mollie_payment_id)->toBe('tr_print123')
        ->and($order->photos[0]['path'])->toBe($media->path)
        ->and($order->status)->toBe(PrintOrderStatus::PendingPayment)
        // Snapshot from the offering, so later admin edits can't change it.
        ->and($order->printdeal_sku)->toBe($this->albumOffering->sku)
        ->and($order->printdeal_attributes)->toBe($this->albumOffering->order_attributes);
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
        'product' => 'album',
        'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        'shipping_address' => [
            'firstName' => 'Oma',
            'lastName' => 'Jansen',
            'street' => 'Dorpsweg',
            'houseNumber' => '12',
            'postalCode' => '5678CD',
            'city' => 'Utrecht',
            'country' => 'NL',
        ],
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertCreated();
});

it('rejects photos from posts the user cannot view', function () {
    $stranger = User::factory()->create();
    [$post, $media] = makePrintablePhoto($stranger);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/print/orders', [
        'product' => 'album',
        'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        'shipping_address' => [
            'firstName' => 'A',
            'lastName' => 'B',
            'street' => 'C',
            'houseNumber' => '1',
            'postalCode' => '1234AB',
            'city' => 'X',
            'country' => 'NL',
        ],
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photos');

    expect(PrintOrder::query()->count())->toBe(0);
});

it('rejects video media and requires a t-shirt size', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id, 'media_type' => 'video']);
    $video = PostMedia::create([
        'post_id' => $post->id,
        'sort_order' => 0,
        'path' => "users/{$user->id}/posts/clip.mp4",
        'type' => 'video',
        'format' => 'mp4',
        'status' => MediaStatus::Ready,
    ]);

    Sanctum::actingAs($user);

    $address = [
        'firstName' => 'A',
        'lastName' => 'B',
        'street' => 'C',
        'houseNumber' => '1',
        'postalCode' => '1234AB',
        'city' => 'X',
        'country' => 'NL',
    ];

    $this->postJson('/api/print/orders', [
        'product' => 'album',
        'photos' => [['post_id' => $post->id, 'media_id' => $video->id]],
        'shipping_address' => $address,
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertJsonValidationErrors('photos');

    [$photoPost, $photo] = makePrintablePhoto($user);

    $this->postJson('/api/print/orders', [
        'product' => 'tshirt',
        'photos' => [['post_id' => $photoPost->id, 'media_id' => $photo->id]],
        'shipping_address' => $address,
        'redirect_url' => 'https://innerr.test/print/return',
    ])->assertJsonValidationErrors('options.size');
});

it('refuses products without an orderable offering', function () {
    $this->albumOffering->update(['enabled' => false]);

    $user = User::factory()->create();
    [$post, $media] = makePrintablePhoto($user);

    Sanctum::actingAs($user);

    $this->postJson('/api/print/orders', [
        'product' => 'album',
        'photos' => [['post_id' => $post->id, 'media_id' => $media->id]],
        'shipping_address' => [
            'firstName' => 'A',
            'lastName' => 'B',
            'street' => 'C',
            'houseNumber' => '1',
            'postalCode' => '1234AB',
            'city' => 'X',
            'country' => 'NL',
        ],
        'redirect_url' => 'https://innerr.test/print/return',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'product_unavailable');
});

it('lists only the user own orders and hides others', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = PrintOrder::factory()->for($user)->create();
    $theirs = PrintOrder::factory()->for($other)->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/print/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);

    $this->getJson("/api/print/orders/{$mine->id}")->assertOk();
    $this->getJson("/api/print/orders/{$theirs->id}")->assertNotFound();
});
