<?php

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use App\Services\Subscriptions\Apple\AppStoreServerApi;
use App\Services\Subscriptions\ChannelRegistry;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Plan::factory()->free()->create();
    $this->plus = Plan::factory()->plus()->create();

    Price::factory()
        ->channel(SubscriptionChannel::Apple)
        ->create([
            'plan_id' => $this->plus->id,
            'is_active' => true,
            'channel_product_id' => 'plus_apple_monthly',
        ]);

    $this->user = User::factory()->create(['apple_id' => 'apple-uid-abc']);

    $this->app->instance(AppleJwsVerifier::class, new AppleJwsVerifier(rootCaPath: '/dev/null', verifyChain: false));
    $this->app->forgetInstance(ChannelRegistry::class);
});

it('rejects webhook without signedPayload', function () {
    $this->postJson('/api/webhooks/subscriptions/apple', [])->assertStatus(422);
});

it('returns 400 when signedPayload cannot be verified', function () {
    $this->postJson('/api/webhooks/subscriptions/apple', ['signedPayload' => 'not-a-jws'])
        ->assertStatus(400);
});

it('persists an event row, dispatches processor, and dedupes by notificationUUID', function () {
    Bus::fake([ProcessSubscriptionEvent::class]);

    [$signedPayload, $notificationUuid] = appleSignedPayload(notificationType: 'SUBSCRIBED');

    $this->postJson('/api/webhooks/subscriptions/apple', ['signedPayload' => $signedPayload])
        ->assertStatus(202);

    expect(SubscriptionEvent::query()->where('external_event_id', $notificationUuid)->count())->toBe(1);
    Bus::assertDispatched(ProcessSubscriptionEvent::class);

    $this->postJson('/api/webhooks/subscriptions/apple', ['signedPayload' => $signedPayload])
        ->assertStatus(200);
    expect(SubscriptionEvent::query()->where('external_event_id', $notificationUuid)->count())->toBe(1);
});

it('processes a SUBSCRIBED event into an active subscription', function () {
    [$signedPayload, $notificationUuid, $originalTx] = appleSignedPayload(
        notificationType: 'SUBSCRIBED',
        appAccountToken: 'apple-uid-abc',
    );

    $api = Mockery::mock(AppStoreServerApi::class);
    $api->shouldReceive('getAllSubscriptionStatuses')
        ->with($originalTx)
        ->andReturn(appleStatusesResponse('plus_apple_monthly', 1));
    $this->app->instance(AppStoreServerApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/webhooks/subscriptions/apple', ['signedPayload' => $signedPayload])
        ->assertStatus(202);

    $event = SubscriptionEvent::query()->where('external_event_id', $notificationUuid)->firstOrFail();
    ProcessSubscriptionEvent::dispatchSync($event->id);

    $sub = Subscription::query()
        ->where('channel', SubscriptionChannel::Apple)
        ->where('channel_subscription_id', $originalTx)
        ->first();

    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->plan_id)->toBe($this->plus->id)
        ->and($sub->user_id)->toBe($this->user->id);
});

it('drops entitlement on REVOKE notification', function () {
    $sub = Subscription::factory()
        ->for($this->user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Apple)
        ->active()
        ->create([
            'channel_subscription_id' => 'orig-tx-revoked-1',
        ]);

    [$signedPayload] = appleSignedPayload(
        notificationType: 'REVOKE',
        originalTransactionId: 'orig-tx-revoked-1',
        appAccountToken: 'apple-uid-abc',
    );

    $api = Mockery::mock(AppStoreServerApi::class);
    $api->shouldReceive('getAllSubscriptionStatuses')
        ->andReturn(appleStatusesResponse('plus_apple_monthly', 5));
    $this->app->instance(AppStoreServerApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/webhooks/subscriptions/apple', ['signedPayload' => $signedPayload])
        ->assertStatus(202);

    $event = SubscriptionEvent::query()->latest('id')->first();
    ProcessSubscriptionEvent::dispatchSync($event->id);

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Refunded)
        ->and($this->user->fresh()->currentPlan()->slug)->toBe('free');
});

/**
 * @return array{0: string, 1: string, 2: string}
 */
function appleSignedPayload(
    string $notificationType,
    ?string $originalTransactionId = null,
    ?string $appAccountToken = null,
): array {
    $originalTx = $originalTransactionId ?? 'orig-tx-'.uniqid();
    $notificationUuid = 'evt-'.uniqid();

    [$privatePem, $der] = applePemAndDer();

    $signedTransaction = JWT::encode([
        'originalTransactionId' => $originalTx,
        'productId' => 'plus_apple_monthly',
        'purchaseDate' => (int) (now()->subMinute()->valueOf()),
        'expiresDate' => (int) (now()->addMonth()->valueOf()),
        'inAppOwnershipType' => 'PURCHASED',
        'environment' => 'Sandbox',
        'appAccountToken' => $appAccountToken,
    ], $privatePem, 'ES256', null, ['alg' => 'ES256', 'x5c' => [$der]]);

    $signedRenewal = JWT::encode([
        'autoRenewStatus' => 1,
        'autoRenewProductId' => 'plus_apple_monthly',
    ], $privatePem, 'ES256', null, ['alg' => 'ES256', 'x5c' => [$der]]);

    $signedPayload = JWT::encode([
        'notificationUUID' => $notificationUuid,
        'notificationType' => $notificationType,
        'subtype' => '',
        'signedDate' => (int) (now()->valueOf()),
        'data' => [
            'environment' => 'Sandbox',
            'bundleId' => 'app.innerr.test',
            'signedTransactionInfo' => $signedTransaction,
            'signedRenewalInfo' => $signedRenewal,
        ],
    ], $privatePem, 'ES256', null, ['alg' => 'ES256', 'x5c' => [$der]]);

    return [$signedPayload, $notificationUuid, $originalTx];
}

/**
 * @return array<string, mixed>
 */
function appleStatusesResponse(string $productId, int $statusCode): array
{
    [$privatePem] = applePemAndDer();
    $now = now();

    $tx = JWT::encode([
        'productId' => $productId,
        'purchaseDate' => (int) ($now->valueOf()),
        'expiresDate' => (int) ($now->copy()->addMonth()->valueOf()),
        'inAppOwnershipType' => 'PURCHASED',
    ], $privatePem, 'ES256');

    $renewal = JWT::encode([
        'autoRenewStatus' => $statusCode === 1 ? 1 : 0,
    ], $privatePem, 'ES256');

    return [
        'environment' => 'Sandbox',
        'bundleId' => 'app.innerr.test',
        'data' => [[
            'status' => $statusCode,
            'signedTransactionInfo' => $tx,
            'signedRenewalInfo' => $renewal,
        ]],
    ];
}

/**
 * @return array{0: string, 1: string}
 */
function applePemAndDer(): array
{
    static $cache;

    if ($cache !== null) {
        return $cache;
    }

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);

    $csr = openssl_csr_new(['CN' => 'TestLeaf'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, days: 1, options: ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);

    $der = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certPem);

    return $cache = [$privatePem, $der];
}
