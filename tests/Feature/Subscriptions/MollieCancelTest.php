<?php

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use Laravel\Sanctum\Sanctum;
use Mollie\Api\Endpoints\CustomerEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;

beforeEach(function () {
    Plan::factory()->free()->create();
    $this->plus = Plan::factory()->plus()->create();
});

it('cancels via Mollie and transitions subscription to canceled', function () {
    $user = User::factory()->create(['mollie_customer_id' => 'cst_abc']);
    Subscription::factory()
        ->for($user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Mollie)
        ->active()
        ->create([
            'channel_subscription_id' => 'sub_mollie_xyz',
            'channel_customer_id' => 'cst_abc',
        ]);

    Sanctum::actingAs($user);

    $client = Mockery::mock(MollieApiClient::class);
    $customers = Mockery::mock(CustomerEndpoint::class);
    $client->customers = $customers;

    $customer = Mockery::mock(Customer::class);
    $customer->shouldReceive('cancelSubscription')
        ->with('sub_mollie_xyz')
        ->once();

    $customers->shouldReceive('get')->with('cst_abc')->andReturn($customer);

    $this->app->instance(MollieApiClient::class, $client);
    $this->app->forgetInstance(ChannelRegistry::class);

    $this->postJson('/api/subscription/web/cancel')
        ->assertOk()
        ->assertJsonPath('current_period_end', fn ($v): bool => $v !== null);

    expect(Subscription::query()->first()->status)->toBe(SubscriptionStatus::Canceled);
});

it('returns 404 when user has no active Mollie subscription', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/subscription/web/cancel')
        ->assertNotFound();
});
