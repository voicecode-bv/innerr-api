<?php

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use App\Services\Subscriptions\Apple\AppStoreServerApi;
use App\Services\Subscriptions\ChannelRegistry;
use Firebase\JWT\JWT;
use Laravel\Sanctum\Sanctum;

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

    $this->app->instance(AppleJwsVerifier::class, new AppleJwsVerifier(rootCaPath: '/dev/null', verifyChain: false));
    $this->app->forgetInstance(ChannelRegistry::class);
});

it('rejects guests', function () {
    $this->postJson('/api/subscription/iap/apple/verify', ['signed_transaction' => 'irrelevant'])
        ->assertUnauthorized();
});

it('returns 409 when user already has an active subscription on another channel', function () {
    $user = User::factory()->create();
    Subscription::factory()
        ->for($user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Mollie)
        ->active()
        ->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/subscription/iap/apple/verify', ['signed_transaction' => 'irrelevant'])
        ->assertStatus(409)
        ->assertJsonPath('blocking_channel', 'mollie');
});

it('verifies, fetches authoritative status from Apple, and creates an active subscription', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);

    $clientToken = JWT::encode([
        'originalTransactionId' => 'apple-orig-12345',
        'productId' => 'plus_apple_monthly',
    ], $privatePem, 'ES256');

    $api = Mockery::mock(AppStoreServerApi::class);
    $api->shouldReceive('getAllSubscriptionStatuses')
        ->with('apple-orig-12345')
        ->andReturn([
            'environment' => 'Sandbox',
            'bundleId' => 'app.innerr.test',
            'data' => [[
                'status' => 1,
                'signedTransactionInfo' => JWT::encode([
                    'productId' => 'plus_apple_monthly',
                    'purchaseDate' => (int) (now()->valueOf()),
                    'expiresDate' => (int) (now()->addMonth()->valueOf()),
                    'inAppOwnershipType' => 'PURCHASED',
                ], $privatePem, 'ES256'),
                'signedRenewalInfo' => JWT::encode([
                    'autoRenewStatus' => 1,
                ], $privatePem, 'ES256'),
            ]],
        ]);
    $this->app->instance(AppStoreServerApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $response = $this->postJson('/api/subscription/iap/apple/verify', ['signed_transaction' => $clientToken])
        ->assertCreated();

    $sub = Subscription::query()->where('channel', SubscriptionChannel::Apple)->first();
    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->plan_id)->toBe($this->plus->id)
        ->and($sub->user_id)->toBe($user->id);

    $response->assertJsonPath('plan', 'plus');
});
