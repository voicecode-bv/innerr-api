<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use Laravel\Sanctum\Sanctum;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

beforeEach(function () {
    Plan::factory()->free()->create();
    $plus = Plan::factory()->plus()->create();
    $this->price = Price::factory()
        ->channel(SubscriptionChannel::Mollie)
        ->interval(BillingInterval::Monthly)
        ->create([
            'plan_id' => $plus->id,
            'is_active' => true,
            'channel_product_id' => 'plus_mollie_monthly_test',
        ]);
});

it('creates a Mollie checkout for an authenticated user without an existing subscription', function () {
    $user = User::factory()->create(['mollie_customer_id' => 'cst_existing']);
    Sanctum::actingAs($user);

    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_test123';
    $payment->_links = (object) ['checkout' => (object) ['href' => 'https://mollie.test/checkout/abc']];

    $payments->shouldReceive('create')
        ->once()
        ->withArgs(function (array $args) use ($user) {
            return $args['sequenceType'] === 'first'
                && $args['customerId'] === 'cst_existing'
                && $args['metadata']['user_id'] === $user->id;
        })
        ->andReturn($payment);

    $this->app->instance(MollieApiClient::class, $client);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/subscription/web/checkout', [
        'price_id' => $this->price->id,
        'redirect_url' => 'https://innerr.test/account/subscription',
    ])
        ->assertCreated()
        ->assertJsonPath('checkout_url', 'https://mollie.test/checkout/abc')
        ->assertJsonPath('reference', 'tr_test123');
});

it('returns 409 when user already has an active subscription on another channel', function () {
    $user = User::factory()->create();
    $plus = Plan::query()->where('slug', 'plus')->first();
    Subscription::factory()
        ->for($user)
        ->for($plus)
        ->channel(SubscriptionChannel::Apple)
        ->active()
        ->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/subscription/web/checkout', [
        'price_id' => $this->price->id,
        'redirect_url' => 'https://innerr.test/done',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'active_subscription_other_channel')
        ->assertJsonPath('blocking_channel', 'apple');
});

it('rejects checkout with an inactive price', function () {
    $this->price->update(['is_active' => false]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/subscription/web/checkout', [
        'price_id' => $this->price->id,
        'redirect_url' => 'https://innerr.test/done',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('price_id');
});

it('rejects guests', function () {
    $this->postJson('/api/subscription/web/checkout', [
        'price_id' => $this->price->id,
        'redirect_url' => 'https://innerr.test/done',
    ])->assertUnauthorized();
});
