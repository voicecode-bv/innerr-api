<?php

use App\Enums\PrintOrderStatus;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintArtworkGenerator;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Store a small generated JPEG on the fake disk and return its path.
 */
function storePrintTestPhoto(string $path): void
{
    $image = imagecreatetruecolor(60, 40);
    imagefilledrectangle($image, 0, 0, 59, 39, imagecolorallocate($image, 200, 120, 40));

    ob_start();
    imagejpeg($image);
    Storage::disk()->put($path, ob_get_clean());
    imagedestroy($image);
}

function fakePrintdeal(): void
{
    Http::fake([
        'api.printdeal.test/login' => Http::response(['token' => 'jwt-token']),
        'api.printdeal.test/order' => Http::response([
            'id' => 'pd-order-uuid',
            'number' => 'DDB2026001234',
            'status' => 'Open',
            'items' => [
                ['id' => 'pd-item-1', 'number' => 'DDB2026001234-1', 'file' => true],
                ['id' => 'pd-item-2', 'number' => 'DDB2026001234-2', 'file' => true],
            ],
        ], 201),
    ]);
    Http::preventStrayRequests();
}

function runSubmit(PrintOrder $order): void
{
    (new SubmitPrintOrder($order))->handle(
        app(PrintdealClient::class),
        app(PrintArtworkGenerator::class),
    );
}

beforeEach(function () {
    Storage::fake();

    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => 'secret-token',
    ]);
    config()->set('print.billing_address.firstName', 'Michael');
    config()->set('print.billing_address.lastName', 'Blijleven');
    config()->set('print.billing_address.email', 'facturen@innerr.app');
    config()->set('print.billing_address.street', 'Straat');
    config()->set('print.billing_address.houseNumber', '1');
    config()->set('print.billing_address.postalCode', '1234AB');
    config()->set('print.billing_address.city', 'Amsterdam');
});

it('generates artwork per item and places one multi-item Printdeal order', function () {
    fakePrintdeal();

    $order = PrintOrder::factory()->paid()->create();
    $album = PrintOrderItem::factory()->for($order, 'order')->create([
        'printdeal_sku' => 'sku-album-uuid',
        'photos' => [
            ['post_id' => 'p1', 'media_id' => 'm1', 'path' => 'photos/a.jpg'],
            ['post_id' => 'p2', 'media_id' => 'm2', 'path' => 'photos/b.jpg'],
        ],
    ]);
    $tshirt = PrintOrderItem::factory()->tshirt('M')->for($order, 'order')->create([
        'printdeal_sku' => 'sku-tshirt-uuid',
        'photos' => [['post_id' => 'p3', 'media_id' => 'm3', 'path' => 'photos/c.jpg']],
    ]);
    storePrintTestPhoto('photos/a.jpg');
    storePrintTestPhoto('photos/b.jpg');
    storePrintTestPhoto('photos/c.jpg');

    runSubmit($order);

    $order->refresh()->load('items');
    $album->refresh();
    $tshirt->refresh();

    expect($order->status)->toBe(PrintOrderStatus::Submitted)
        ->and($order->printdeal_order_id)->toBe('pd-order-uuid')
        ->and($order->printdeal_order_number)->toBe('DDB2026001234')
        ->and($album->printdeal_item_id)->toBe('pd-item-1')
        ->and($tshirt->printdeal_item_id)->toBe('pd-item-2')
        ->and($album->pdf_path)->toBe("print-orders/{$order->id}/{$album->id}.pdf")
        ->and(Storage::disk()->get($album->pdf_path))->toStartWith('%PDF')
        ->and(Storage::disk()->get($tshirt->pdf_path))->toStartWith('%PDF');

    Http::assertSent(function ($request) use ($order): bool {
        if (! str_ends_with($request->url(), '/order')) {
            return true;
        }

        $items = $request['items'];
        $variant = collect($items[1]['variants'][0])->keyBy('attribute');

        return $request['testOrder'] === true
            && $request['paymentMethod'] === 'onAccount'
            && $request['platform'] === 'Own'
            && $request['reference'] === "innerr-{$order->id}"
            && count($items) === 2
            // Album: plain product, quantity on the address.
            && $items[0]['sku'] === 'sku-album-uuid'
            && $items[0]['shippingAddresses'][0]['quantity'] === 1
            && str_contains($items[0]['files'][0]['url'], '.pdf')
            // T-shirt: grouped product, options + quantity as a variant.
            && $items[1]['sku'] === 'sku-tshirt-uuid'
            && $variant['Size']['value'] === 'M'
            && $variant['Quantity']['value'] === '1'
            && ! array_key_exists('quantity', $items[1]['shippingAddresses'][0]);
    });
});

it('does nothing for orders that are not in the paid state', function () {
    Http::fake();
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->withItem()->create();

    runSubmit($order);

    expect($order->fresh()->status)->toBe(PrintOrderStatus::PendingPayment);
    Http::assertNothingSent();
});

it('marks the order failed when retries are exhausted', function () {
    $order = PrintOrder::factory()->withItem()->paid()->create();

    (new SubmitPrintOrder($order))->failed(new RuntimeException('printdeal down'));

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Failed);
});
