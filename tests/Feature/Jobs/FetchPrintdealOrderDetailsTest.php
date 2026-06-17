<?php

use App\Jobs\FetchPrintdealOrderDetails;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function runFetchDetails(PrintOrder $order): void
{
    (new FetchPrintdealOrderDetails($order))->handle(app(PrintdealClient::class));
}

beforeEach(function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => 'secret-token',
    ]);
});

it('stores the number, status and orderline ids on the order and its items', function () {
    Http::fake([
        'api.printdeal.test/orders/pd-order-uuid' => Http::response([
            'number' => 'DDB2026001234',
            'status' => 'in-progress',
            'lines' => [
                ['id' => 111, 'status' => 'in-progress'],
                ['id' => 222, 'status' => 'queued'],
            ],
        ]),
    ]);
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->submitted()->create([
        'printdeal_order_id' => 'pd-order-uuid',
        'printdeal_order_number' => null,
    ]);
    $first = PrintOrderItem::factory()->for($order, 'order')->create();
    $second = PrintOrderItem::factory()->for($order, 'order')->create();

    runFetchDetails($order);

    expect($order->fresh()->printdeal_order_number)->toBe('DDB2026001234')
        ->and($order->fresh()->printdeal_status)->toBe('in-progress')
        ->and($first->fresh()->printdeal_item_id)->toBe('111')
        ->and($second->fresh()->printdeal_item_id)->toBe('222')
        ->and($second->fresh()->printdeal_status)->toBe('queued');
});

it('retries while Printdeal is still building the order (404)', function () {
    Http::fake([
        'api.printdeal.test/orders/pd-order-uuid' => Http::response([
            'message' => 'Order creation progress is still in running.',
        ], 404),
    ]);
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->submitted()->create([
        'printdeal_order_id' => 'pd-order-uuid',
        'printdeal_order_number' => null,
    ]);

    // The 404 bubbles up as a RequestException so the queue retries on backoff.
    runFetchDetails($order);
})->throws(RequestException::class);

it('retries when the order exists but has no number yet', function () {
    Http::fake([
        'api.printdeal.test/orders/pd-order-uuid' => Http::response([
            'status' => 'in-progress',
            'lines' => [],
        ]),
    ]);
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->submitted()->create([
        'printdeal_order_id' => 'pd-order-uuid',
        'printdeal_order_number' => null,
    ]);

    runFetchDetails($order);
})->throws(RuntimeException::class);

it('does nothing when the details were already fetched', function () {
    Http::fake();
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->submitted()->create([
        'printdeal_order_id' => 'pd-order-uuid',
        'printdeal_order_number' => 'DDB2026001234',
    ]);

    runFetchDetails($order);

    Http::assertNothingSent();
});

it('does nothing when the order was never placed', function () {
    Http::fake();
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->paid()->create(['printdeal_order_id' => null]);

    runFetchDetails($order);

    Http::assertNothingSent();
});
