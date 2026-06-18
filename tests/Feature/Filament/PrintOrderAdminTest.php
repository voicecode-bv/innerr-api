<?php

use App\Enums\PrintOrderStatus;
use App\Filament\Resources\PrintOrders\Pages\ListPrintOrders;
use App\Filament\Resources\PrintOrders\Pages\ViewPrintOrder;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);
});

it('lists print orders', function () {
    $order = PrintOrder::factory()->withItem()->submitted()->create();

    Livewire::test(ListPrintOrders::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$order]);
});

it('shows an order with its items on the view page', function () {
    $order = PrintOrder::factory()->paid()->create();
    $item = PrintOrderItem::factory()->for($order, 'order')->create(['app_product' => 'canvas']);

    Livewire::test(ViewPrintOrder::class, ['record' => $order->id])
        ->assertSuccessful()
        ->assertSee('canvas');
});

it('refreshes an order from the Printdeal API', function () {
    Http::fake([
        'api.printdeal.test/orders/pd-uuid' => Http::response([
            'number' => 'DDB2026009999',
            'status' => 'Confirmed',
            'lines' => [['id' => 555, 'status' => 'Confirmed']],
        ]),
    ]);
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->submitted()->create([
        'printdeal_order_id' => 'pd-uuid',
        'printdeal_order_number' => null,
    ]);
    $item = PrintOrderItem::factory()->for($order, 'order')->create();

    Livewire::test(ListPrintOrders::class)
        ->callAction(TestAction::make('refreshFromPrintdeal')->table($order));

    expect($order->fresh()->printdeal_order_number)->toBe('DDB2026009999')
        ->and($order->fresh()->printdeal_status)->toBe('Confirmed')
        ->and($item->fresh()->printdeal_item_id)->toBe('555');
});

it('resubmits a failed order that never reached Printdeal', function () {
    Queue::fake();

    $order = PrintOrder::factory()->withItem()->create([
        'status' => PrintOrderStatus::Failed,
        'printdeal_order_id' => null,
    ]);

    Livewire::test(ListPrintOrders::class)
        ->callAction(TestAction::make('resubmit')->table($order));

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Paid);
    Queue::assertPushed(SubmitPrintOrder::class);
});

it('refuses to resubmit a failed order already placed at Printdeal', function () {
    Queue::fake();

    $order = PrintOrder::factory()->withItem()->create([
        'status' => PrintOrderStatus::Failed,
        'printdeal_order_id' => 'pd-uuid',
    ]);

    Livewire::test(ListPrintOrders::class)
        ->callAction(TestAction::make('resubmit')->table($order));

    // Left untouched; a resubmission would duplicate the placed order.
    expect($order->fresh()->status)->toBe(PrintOrderStatus::Failed);
    Queue::assertNothingPushed();
});
