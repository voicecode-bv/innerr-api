<?php

use App\Enums\PrintOrderStatus;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use Illuminate\Support\Facades\Queue;
use Mollie\Api\Endpoints\PaymentEndpoint;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

function fakeMolliePayment(string $orderId, string $status, ?string $paidAt = null): void
{
    $client = Mockery::mock(MollieApiClient::class);
    $payments = Mockery::mock(PaymentEndpoint::class);
    $client->payments = $payments;

    $payment = new Payment($client);
    $payment->id = 'tr_print123';
    $payment->status = $status;
    $payment->paidAt = $paidAt;
    $payment->metadata = (object) ['kind' => 'print_order', 'print_order_id' => $orderId];

    $payments->shouldReceive('get')->with('tr_print123')->andReturn($payment);

    app()->instance(MollieApiClient::class, $client);
}

it('marks the order paid and queues the Printdeal submission', function () {
    Queue::fake();
    $order = PrintOrder::factory()->create();
    fakeMolliePayment($order->id, 'paid', now()->toIso8601String());

    $this->postJson('/api/webhooks/print/mollie', ['id' => 'tr_print123'])
        ->assertOk();

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Paid);
    Queue::assertPushed(SubmitPrintOrder::class, 1);
});

it('cancels the order when the payment fails', function () {
    Queue::fake();
    $order = PrintOrder::factory()->create();
    fakeMolliePayment($order->id, 'failed');

    $this->postJson('/api/webhooks/print/mollie', ['id' => 'tr_print123'])
        ->assertOk();

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Canceled);
    Queue::assertNothingPushed();
});

it('is idempotent for repeated webhooks after payment', function () {
    Queue::fake();
    $order = PrintOrder::factory()->submitted()->create();
    fakeMolliePayment($order->id, 'paid', now()->toIso8601String());

    $this->postJson('/api/webhooks/print/mollie', ['id' => 'tr_print123'])
        ->assertOk();

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Submitted);
    Queue::assertNothingPushed();
});

it('acknowledges unknown orders without erroring', function () {
    Queue::fake();
    fakeMolliePayment('00000000-0000-0000-0000-000000000000', 'paid', now()->toIso8601String());

    $this->postJson('/api/webhooks/print/mollie', ['id' => 'tr_print123'])
        ->assertOk();

    Queue::assertNothingPushed();
});

it('rejects a payload without payment id', function () {
    $this->postJson('/api/webhooks/print/mollie', [])->assertStatus(422);
});
