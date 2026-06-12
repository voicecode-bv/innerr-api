<?php

use App\Models\PrintOrder;
use App\Models\PrintOrderItem;

beforeEach(function () {
    config()->set('services.printdeal.webhook_token', 'secret-token');
});

it('rejects a wrong webhook token', function () {
    $this->postJson('/api/webhooks/print/printdeal/wrong-token', [
        'orderId' => 'whatever',
        'status' => 'InProduction',
    ])->assertForbidden();
});

it('rejects webhooks entirely when no token is configured', function () {
    config()->set('services.printdeal.webhook_token', null);

    $this->postJson('/api/webhooks/print/printdeal/secret-token', [
        'orderId' => 'whatever',
    ])->assertForbidden();
});

it('updates the printdeal status of a known order', function () {
    $order = PrintOrder::factory()->submitted()->create();

    $this->postJson('/api/webhooks/print/printdeal/secret-token', [
        'orderId' => $order->printdeal_order_id,
        'status' => 'InProduction',
    ])->assertOk();

    expect($order->fresh()->printdeal_status)->toBe('InProduction');
});

it('updates the matching line item when the event names an orderline', function () {
    $order = PrintOrder::factory()->submitted()->create();
    $item = PrintOrderItem::factory()->for($order, 'order')->create([
        'printdeal_item_id' => 'pd-item-1',
    ]);
    $untouched = PrintOrderItem::factory()->for($order, 'order')->create([
        'printdeal_item_id' => 'pd-item-2',
    ]);

    $this->postJson('/api/webhooks/print/printdeal/secret-token', [
        'orderId' => $order->printdeal_order_id,
        'orderlineId' => 'pd-item-1',
        'status' => 'Shipped',
    ])->assertOk();

    expect($item->fresh()->printdeal_status)->toBe('Shipped')
        ->and($untouched->fresh()->printdeal_status)->toBeNull()
        ->and($order->fresh()->printdeal_status)->toBe('Shipped');
});

it('acknowledges unknown orders and unrecognizable payloads', function () {
    $this->postJson('/api/webhooks/print/printdeal/secret-token', [
        'orderId' => 'not-ours',
        'status' => 'Shipped',
    ])->assertOk();

    $this->postJson('/api/webhooks/print/printdeal/secret-token', [
        'something' => 'else',
    ])->assertOk();
});
