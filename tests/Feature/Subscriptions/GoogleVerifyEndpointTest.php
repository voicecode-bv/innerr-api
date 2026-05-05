<?php

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Google\PlayDeveloperApi;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Plan::factory()->free()->create();
    $this->plus = Plan::factory()->plus()->create();

    Price::factory()
        ->channel(SubscriptionChannel::Google)
        ->create([
            'plan_id' => $this->plus->id,
            'is_active' => true,
            'channel_product_id' => 'plus_google_monthly',
        ]);
});

it('rejects guests', function () {
    $this->postJson('/api/subscription/iap/google/verify', [
        'purchase_token' => 'x',
        'product_id' => 'plus_google_monthly',
    ])->assertUnauthorized();
});

it('returns 409 when user has subscription on another channel', function () {
    $user = User::factory()->create();
    Subscription::factory()
        ->for($user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Apple)
        ->active()
        ->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/subscription/iap/google/verify', [
        'purchase_token' => 'tok-1',
        'product_id' => 'plus_google_monthly',
    ])
        ->assertStatus(409)
        ->assertJsonPath('blocking_channel', 'apple');
});

it('verifies and creates a Google subscription', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $api = Mockery::mock(PlayDeveloperApi::class);
    $api->shouldReceive('getSubscriptionV2')
        ->with('purchase-token-xyz')
        ->andReturn([
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'startTime' => now()->toIso8601String(),
            'lineItems' => [[
                'productId' => 'plus_google_monthly',
                'expiryTime' => now()->addMonth()->toIso8601String(),
                'autoRenewingPlan' => ['autoRenewEnabled' => true],
            ]],
        ]);
    $this->app->instance(PlayDeveloperApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/subscription/iap/google/verify', [
        'purchase_token' => 'purchase-token-xyz',
        'product_id' => 'plus_google_monthly',
    ])
        ->assertCreated()
        ->assertJsonPath('plan', 'plus');

    $sub = Subscription::query()->where('channel', SubscriptionChannel::Google)->first();
    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->user_id)->toBe($user->id)
        ->and($sub->plan_id)->toBe($this->plus->id);
});

it('handles testPurchase object in subscriptionsv2 response without crashing', function () {
    // Regression: Play Developer API returns testPurchase as an empty object {}
    // for license-tester / sandbox purchases. Casting that array to string used
    // to throw "Array to string conversion" during dtoFromRemote.
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $api = Mockery::mock(PlayDeveloperApi::class);
    $api->shouldReceive('getSubscriptionV2')
        ->with('sandbox-token')
        ->andReturn([
            'kind' => 'androidpublisher#subscriptionPurchaseV2',
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'startTime' => now()->toIso8601String(),
            'testPurchase' => [], // Google v2 returns {} which json_decode renders as []
            'lineItems' => [[
                'productId' => 'plus_google_monthly',
                'expiryTime' => now()->addMonth()->toIso8601String(),
                'autoRenewingPlan' => ['autoRenewEnabled' => true],
            ]],
        ]);
    $this->app->instance(PlayDeveloperApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/subscription/iap/google/verify', [
        'purchase_token' => 'sandbox-token',
        'product_id' => 'plus_google_monthly',
    ])
        ->assertCreated();

    $sub = Subscription::query()->where('channel', SubscriptionChannel::Google)->first();
    expect($sub->environment)->toBe('sandbox');
});
