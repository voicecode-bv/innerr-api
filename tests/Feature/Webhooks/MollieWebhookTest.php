<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use Illuminate\Support\Facades\Bus;
use Mollie\Api\Endpoints\CustomerEndpoint;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Subscription as MollieSubscription;

beforeEach(function () {
    Plan::factory()->free()->create();
    $this->plus = Plan::factory()->plus()->create();
    $this->price = Price::factory()
        ->channel(SubscriptionChannel::Mollie)
        ->interval(BillingInterval::Monthly)
        ->create([
            'plan_id' => $this->plus->id,
            'is_active' => true,
            'channel_product_id' => 'plus_mollie_monthly_test',
        ]);
    $this->user = User::factory()->create(['mollie_customer_id' => 'cst_abc']);
});

it('returns 422 when payment id is missing', function () {
    $this->postJson('/api/webhooks/subscriptions/mollie')->assertStatus(422);
});

it('persists an event row and dispatches the processor job', function () {
    Bus::fake([ProcessSubscriptionEvent::class]);

    $this->postJson('/api/webhooks/subscriptions/mollie', ['id' => 'tr_first_payment'])
        ->assertStatus(202);

    expect(SubscriptionEvent::query()->where('external_event_id', 'tr_first_payment')->exists())->toBeTrue();
    Bus::assertDispatched(ProcessSubscriptionEvent::class);
});

it('is idempotent on duplicate webhook delivery', function () {
    Bus::fake([ProcessSubscriptionEvent::class]);

    $this->postJson('/api/webhooks/subscriptions/mollie', ['id' => 'tr_dup'])->assertStatus(202);
    $this->postJson('/api/webhooks/subscriptions/mollie', ['id' => 'tr_dup'])->assertStatus(200);

    expect(SubscriptionEvent::query()->where('external_event_id', 'tr_dup')->count())->toBe(1);
});

it('processes a paid first payment by creating a Mollie subscription and an active local subscription', function () {
    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $customers = Mockery::mock(CustomerEndpoint::class);
    $client->payments = $payments;
    $client->customers = $customers;

    $payment = new Payment($client);
    $payment->id = 'tr_first_paid';
    $payment->status = 'paid';
    $payment->paidAt = now()->toIso8601String();
    $payment->sequenceType = 'first';
    $payment->subscriptionId = null;
    $payment->customerId = 'cst_abc';
    $payment->amount = (object) ['currency' => 'EUR', 'value' => '4.99'];
    $payment->amountRefunded = null;
    $payment->metadata = (object) [
        'user_id' => $this->user->id,
        'price_id' => $this->price->id,
        'plan_id' => $this->plus->id,
        'kind' => 'first_payment',
        'interval' => 'monthly',
    ];

    $payments->shouldReceive('get')->with('tr_first_paid')->andReturn($payment);

    $customer = Mockery::mock(Customer::class);
    $mollieSub = new MollieSubscription($client);
    $mollieSub->id = 'sub_recurring_xyz';
    $customer->shouldReceive('createSubscription')->once()->andReturn($mollieSub);
    $customers->shouldReceive('get')->with('cst_abc')->andReturn($customer);

    $this->app->instance(MollieApiClient::class, $client);
    $this->app->forgetInstance(ChannelRegistry::class);

    $event = SubscriptionEvent::factory()->create([
        'subscription_id' => null,
        'channel' => SubscriptionChannel::Mollie,
        'external_event_id' => 'tr_first_paid',
        'payload' => ['raw_id' => 'tr_first_paid'],
    ]);

    ProcessSubscriptionEvent::dispatchSync($event->id);

    $sub = Subscription::query()->first();
    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->channel_subscription_id)->toBe('sub_recurring_xyz')
        ->and($sub->plan_id)->toBe($this->plus->id);

    expect(SubscriptionTransaction::query()->where('external_transaction_id', 'tr_first_paid')->exists())->toBeTrue();
    expect($event->fresh()->processed_at)->not->toBeNull();
});

it('drops entitlement immediately on a refund payment event', function () {
    $sub = Subscription::factory()
        ->for($this->user)
        ->for($this->plus)
        ->channel(SubscriptionChannel::Mollie)
        ->active()
        ->create([
            'channel_subscription_id' => 'sub_mollie_existing',
            'channel_customer_id' => 'cst_abc',
        ]);

    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_refund';
    $payment->status = 'paid';
    $payment->paidAt = now()->toIso8601String();
    $payment->sequenceType = 'recurring';
    $payment->subscriptionId = 'sub_mollie_existing';
    $payment->customerId = 'cst_abc';
    $payment->amount = (object) ['currency' => 'EUR', 'value' => '4.99'];
    $payment->amountRefunded = (object) ['currency' => 'EUR', 'value' => '4.99'];
    $payment->metadata = (object) [
        'user_id' => $this->user->id,
        'price_id' => $this->price->id,
        'plan_id' => $this->plus->id,
    ];

    $payments->shouldReceive('get')->with('tr_refund')->andReturn($payment);

    $this->app->instance(MollieApiClient::class, $client);

    $event = SubscriptionEvent::factory()->create([
        'subscription_id' => $sub->id,
        'channel' => SubscriptionChannel::Mollie,
        'external_event_id' => 'tr_refund',
        'payload' => ['raw_id' => 'tr_refund'],
    ]);

    ProcessSubscriptionEvent::dispatchSync($event->id);

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Refunded)
        ->and($this->user->fresh()->currentPlan()->slug)->toBe('free');
});
