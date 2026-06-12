<?php

use App\Enums\PrintOrderStatus;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
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
            'items' => [['id' => 'pd-item-uuid', 'number' => 'DDB2026001234-1', 'file' => true]],
        ], 201),
    ]);
    Http::preventStrayRequests();
}

beforeEach(function () {
    Storage::fake();

    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
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

it('generates the artwork and places the Printdeal order', function () {
    fakePrintdeal();

    $order = PrintOrder::factory()->paid()->create([
        'product' => 'album',
        'printdeal_sku' => 'sku-album-uuid',
        'photos' => [
            ['post_id' => 'p1', 'media_id' => 'm1', 'path' => 'photos/a.jpg'],
            ['post_id' => 'p2', 'media_id' => 'm2', 'path' => 'photos/b.jpg'],
        ],
    ]);
    storePrintTestPhoto('photos/a.jpg');
    storePrintTestPhoto('photos/b.jpg');

    (new SubmitPrintOrder($order))->handle(
        app(PrintdealClient::class),
        app(PrintArtworkGenerator::class),
    );

    $order->refresh();

    expect($order->status)->toBe(PrintOrderStatus::Submitted)
        ->and($order->printdeal_order_id)->toBe('pd-order-uuid')
        ->and($order->printdeal_order_number)->toBe('DDB2026001234')
        ->and($order->printdeal_item_id)->toBe('pd-item-uuid')
        ->and($order->pdf_path)->toBe("print-orders/{$order->id}/artwork.pdf");

    expect(Storage::disk()->exists($order->pdf_path))->toBeTrue()
        ->and(Storage::disk()->get($order->pdf_path))->toStartWith('%PDF');

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/order')) {
            return true;
        }

        $item = $request['items'][0];

        return $request['testOrder'] === true
            && $request['paymentMethod'] === 'onAccount'
            && $request['platform'] === 'Own'
            && $item['sku'] === 'sku-album-uuid'
            && str_contains($item['files'][0]['url'], 'artwork.pdf')
            && $item['shippingAddresses'][0]['quantity'] === 1;
    });
});

it('sends t-shirt sizes as a variant without address quantity', function () {
    fakePrintdeal();

    $order = PrintOrder::factory()->paid()->create([
        'product' => 'tshirt',
        'options' => ['size' => 'M'],
        'photos' => [['post_id' => 'p1', 'media_id' => 'm1', 'path' => 'photos/a.jpg']],
    ]);
    storePrintTestPhoto('photos/a.jpg');

    (new SubmitPrintOrder($order))->handle(
        app(PrintdealClient::class),
        app(PrintArtworkGenerator::class),
    );

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Submitted);

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/order')) {
            return true;
        }

        $item = $request['items'][0];
        $variant = collect($item['variants'][0])->keyBy('attribute');

        return $variant['Size']['value'] === 'M'
            && $variant['Quantity']['value'] === '1'
            && ! array_key_exists('quantity', $item['shippingAddresses'][0]);
    });
});

it('does nothing for orders that are not in the paid state', function () {
    Http::fake();
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->create();

    (new SubmitPrintOrder($order))->handle(
        app(PrintdealClient::class),
        app(PrintArtworkGenerator::class),
    );

    expect($order->fresh()->status)->toBe(PrintOrderStatus::PendingPayment);
    Http::assertNothingSent();
});

it('marks the order failed when retries are exhausted', function () {
    $order = PrintOrder::factory()->paid()->create();

    (new SubmitPrintOrder($order))->failed(new RuntimeException('printdeal down'));

    expect($order->fresh()->status)->toBe(PrintOrderStatus::Failed);
});
