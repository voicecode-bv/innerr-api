<?php

use App\Enums\PrintOrderStatus;
use App\Jobs\FetchPrintdealOrderDetails;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintArtworkGenerator;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;

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
        // Creation only returns the uuid; the job follows up with a details
        // fetch for the number, status, and orderline ids.
        'api.printdeal.test/orders/pd-order-uuid' => Http::response([
            'id' => 1234567,
            'number' => 'DDB2026001234',
            'status' => 'in-progress',
            'lines' => [
                ['id' => 111, 'status' => 'in-progress'],
                ['id' => 222, 'status' => 'in-progress'],
            ],
        ]),
        'api.printdeal.test/orders' => Http::response(['uuid' => 'pd-order-uuid'], 201),
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

    // The order-details fetch is its own delayed, retrying job now; fake the
    // queue so it is recorded but not run inline during these tests.
    Queue::fake();

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

it('generates artwork per item and places one multi-line Printdeal order', function () {
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
        ->and($album->pdf_path)->toBe("print-orders/{$order->id}/{$album->id}.pdf")
        ->and(Storage::disk()->get($album->pdf_path))->toStartWith('%PDF')
        ->and(Storage::disk()->get($tshirt->pdf_path))->toStartWith('%PDF');

    // The number, status, and orderline ids are filled in by the follow-up
    // job, queued for the placed order.
    Queue::assertPushed(FetchPrintdealOrderDetails::class, fn (FetchPrintdealOrderDetails $job): bool => $job->printOrder->is($order));

    Http::assertSent(function ($request) use ($order, $album): bool {
        if ($request->method() !== 'POST') {
            return true;
        }

        $lines = $request['orderLines'];
        $tshirtAttributes = collect($lines[1]['attributes'])->keyBy('attribute');
        $shipping = $order->shipping_address;

        return $request['testOrder'] === true
            && $request['reference'] === "innerr-{$order->number}"
            && $request['deliveryMethod'] === 1
            && $request['invoiceAddress']['email'] === 'facturen@innerr.app'
            && $request['invoiceAddress']['housenumber'] === '1'
            && $request['invoiceAddress']['zipcode'] === '1234AB'
            // Stored addresses keep the app's houseNumber/postalCode naming
            // and are translated to the v2 field names on the way out.
            && $request['deliveryAddress']['housenumber'] === $shipping['houseNumber']
            && $request['deliveryAddress']['zipcode'] === $shipping['postalCode']
            && ! array_key_exists('houseNumber', $request['deliveryAddress'])
            && count($lines) === 2
            // Album: configured attributes plus quantity as an attribute.
            && $lines[0]['sku'] === 'sku-album-uuid'
            && $lines[0]['externalId'] === $album->id
            && collect($lines[0]['attributes'])->keyBy('attribute')['quantity']['value'] === '1'
            && str_contains($lines[0]['files'][0]['url'], '.pdf')
            // T-shirt: user options travel as plain attributes in v2.
            && $lines[1]['sku'] === 'sku-tshirt-uuid'
            && $tshirtAttributes['Size']['value'] === 'M'
            && $tshirtAttributes['quantity']['value'] === '1';
    });
});

it('places the order and defers the details fetch to its own job', function () {
    Http::fake([
        'api.printdeal.test/orders' => Http::response(['uuid' => 'pd-order-uuid'], 201),
    ]);
    Http::preventStrayRequests();

    $order = PrintOrder::factory()->withItem()->paid()->create();
    storePrintTestPhoto($order->items()->first()->photos[0]['path']);

    runSubmit($order);

    // The order is placed immediately; the number/status/line ids are left to
    // the deferred job (Printdeal builds the order asynchronously), so they
    // are still empty right after submission.
    expect($order->fresh()->status)->toBe(PrintOrderStatus::Submitted)
        ->and($order->fresh()->printdeal_order_id)->toBe('pd-order-uuid')
        ->and($order->fresh()->printdeal_order_number)->toBeNull();

    Queue::assertPushed(FetchPrintdealOrderDetails::class);
});

it('logs the full Printdeal payload including the artwork download url', function () {
    fakePrintdeal();

    $handler = new TestHandler;
    Log::forgetChannel('print');
    config(['logging.channels.print' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::channel('print')->getLogger()->setHandlers([$handler]);

    $order = PrintOrder::factory()->withItem()->paid()->create();
    storePrintTestPhoto($order->items()->first()->photos[0]['path']);

    runSubmit($order);

    $record = collect($handler->getRecords())
        ->first(fn ($r): bool => str_contains($r['message'], 'submitting order to Printdeal'));

    expect($record)->not->toBeNull();

    $payload = $record['context']['payload'];
    $url = $payload['orderLines'][0]['files'][0]['url'];

    // Logged in full, with its query string (the temporary credential) intact
    // and not redacted, so the exact file Printdeal received can be fetched.
    expect($url)
        ->toContain('.pdf?')
        ->not->toContain('<redacted>')
        ->and($payload['reference'])->toBe("innerr-{$order->number}");
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
