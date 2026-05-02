<?php

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Google\PlayDeveloperApi;
use App\Services\Subscriptions\Google\PubSubOidcVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

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

    // Skip OIDC signature verification in tests
    $this->app->instance(PubSubOidcVerifier::class, new PubSubOidcVerifier(
        http: app(HttpFactory::class),
        cache: Cache::store(),
        jwksUrl: 'https://example.invalid/jwks',
        jwksCacheTtl: 60,
        expectedAudience: null,
        verifySignature: false,
    ));
    $this->app->forgetInstance(ChannelRegistry::class);
});

it('rejects empty Pub/Sub payload', function () {
    $this->postJson('/api/webhooks/subscriptions/google', [])->assertStatus(400);
});

it('persists an event row, dispatches processor, and dedupes by message id', function () {
    Bus::fake([ProcessSubscriptionEvent::class]);

    $envelope = pubSubEnvelope([
        'packageName' => 'app.innerr.android',
        'eventTimeMillis' => (string) (now()->valueOf()),
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 4,
            'purchaseToken' => 'tok-abc',
            'subscriptionId' => 'plus_google_monthly',
        ],
    ], 'msg-1');

    $this->postJson('/api/webhooks/subscriptions/google', $envelope)
        ->assertStatus(202);

    expect(SubscriptionEvent::query()->where('external_event_id', 'msg-1')->count())->toBe(1);
    Bus::assertDispatched(ProcessSubscriptionEvent::class);

    $this->postJson('/api/webhooks/subscriptions/google', $envelope)
        ->assertStatus(200);
    expect(SubscriptionEvent::query()->where('external_event_id', 'msg-1')->count())->toBe(1);
});

it('processes a SUBSCRIPTION_PURCHASED event into an active subscription', function () {
    $user = User::factory()->create(['google_id' => 'google-uid-1']);

    $api = Mockery::mock(PlayDeveloperApi::class);
    $api->shouldReceive('getSubscriptionV2')
        ->with('tok-purchase')
        ->andReturn(playSubscriptionV2Response('SUBSCRIPTION_STATE_ACTIVE', 'plus_google_monthly'));
    $this->app->instance(PlayDeveloperApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $envelope = pubSubEnvelope([
        'packageName' => 'app.innerr.android',
        'eventTimeMillis' => (string) (now()->valueOf()),
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 4,
            'purchaseToken' => 'tok-purchase',
            'subscriptionId' => 'plus_google_monthly',
            'obfuscatedExternalAccountId' => 'google-uid-1',
        ],
    ], 'msg-purchase');

    $this->postJson('/api/webhooks/subscriptions/google', $envelope)->assertStatus(202);

    $event = SubscriptionEvent::query()->where('external_event_id', 'msg-purchase')->firstOrFail();
    ProcessSubscriptionEvent::dispatchSync($event->id);

    $sub = Subscription::query()
        ->where('channel', SubscriptionChannel::Google)
        ->where('channel_subscription_id', 'tok-purchase')
        ->first();

    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->plan_id)->toBe($this->plus->id)
        ->and($sub->user_id)->toBe($user->id);
});

it('drops entitlement on voidedPurchaseNotification', function () {
    $user = User::factory()->create(['google_id' => 'google-uid-2']);
    $sub = Subscription::factory()
        ->for($user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Google)
        ->active()
        ->create([
            'channel_subscription_id' => 'tok-voided',
        ]);

    $api = Mockery::mock(PlayDeveloperApi::class);
    $api->shouldReceive('getSubscriptionV2')
        ->with('tok-voided')
        ->andReturn(playSubscriptionV2Response('SUBSCRIPTION_STATE_CANCELED', 'plus_google_monthly'));
    $this->app->instance(PlayDeveloperApi::class, $api);
    $this->app->forgetInstance(ChannelRegistry::class);

    $envelope = pubSubEnvelope([
        'packageName' => 'app.innerr.android',
        'eventTimeMillis' => (string) (now()->valueOf()),
        'voidedPurchaseNotification' => [
            'purchaseToken' => 'tok-voided',
            'orderId' => 'GPA.0000-0000-0000-00000',
            'productType' => 1,
            'refundType' => 1,
        ],
    ], 'msg-voided');

    $this->postJson('/api/webhooks/subscriptions/google', $envelope)->assertStatus(202);
    $event = SubscriptionEvent::query()->where('external_event_id', 'msg-voided')->firstOrFail();
    ProcessSubscriptionEvent::dispatchSync($event->id);

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Refunded)
        ->and($user->fresh()->currentPlan()->slug)->toBe('free');
});

/**
 * @param  array<string, mixed>  $rtdn
 * @return array<string, mixed>
 */
function pubSubEnvelope(array $rtdn, string $messageId): array
{
    return [
        'message' => [
            'messageId' => $messageId,
            'data' => base64_encode(json_encode($rtdn)),
            'publishTime' => now()->toIso8601String(),
        ],
        'subscription' => 'projects/test/subscriptions/innerr-rtdn',
    ];
}

/**
 * @return array<string, mixed>
 */
function playSubscriptionV2Response(string $state, string $productId): array
{
    $now = now();

    return [
        'kind' => 'androidpublisher#subscriptionPurchaseV2',
        'subscriptionState' => $state,
        'startTime' => $now->toIso8601String(),
        'regionCode' => 'NL',
        'lineItems' => [[
            'productId' => $productId,
            'expiryTime' => $now->copy()->addMonth()->toIso8601String(),
            'autoRenewingPlan' => ['autoRenewEnabled' => $state === 'SUBSCRIPTION_STATE_ACTIVE'],
        ]],
        'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
    ];
}
