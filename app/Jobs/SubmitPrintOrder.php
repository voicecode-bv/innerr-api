<?php

namespace App\Jobs;

use App\Enums\PrintOrderStatus;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintArtworkGenerator;
use App\Services\Printdeal\PrintdealAttributes;
use App\Services\Printdeal\PrintdealClient;
use App\Support\MediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * After a paid Mollie payment: generate a print-ready PDF per line item,
 * store them, and place the whole order at Printdeal as one order with
 * multiple order lines. Split from the webhook request so a slow PDF render
 * or a Printdeal outage never blocks the webhook response, and retries get
 * the full backoff treatment.
 */
class SubmitPrintOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    // Stays under the queue connection's retry_after (90s) so a slow render
    // is never re-dispatched while still running.
    public int $timeout = 80;

    // How long the artwork download URL handed to Printdeal stays valid.
    // Printdeal fetches the file asynchronously while it builds the order, so
    // the window is generous; it stays under the SigV4 7-day presign maximum.
    private const ARTWORK_URL_TTL_DAYS = 6;

    public function __construct(
        public PrintOrder $printOrder,
    ) {}

    public function handle(PrintdealClient $printdeal, PrintArtworkGenerator $artwork): void
    {
        $order = $this->printOrder->fresh(['items']);

        // Idempotent: webhooks can fire multiple times for one payment, and a
        // timed-out attempt may already have placed the Printdeal order.
        if ($order->status !== PrintOrderStatus::Paid || $order->printdeal_order_id !== null) {
            return;
        }

        Log::channel('print')->info('SubmitPrintOrder: started.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'item_count' => $order->items->count(),
            'attempt' => $this->attempts(),
        ]);

        $disk = MediaUrl::disk();
        $orderLines = [];

        foreach ($order->items as $item) {
            // Reuse PDFs from a previous attempt that failed after rendering.
            $pdfPath = $item->pdf_path;

            if ($pdfPath === null || ! $disk->exists($pdfPath)) {
                $pdfPath = "print-orders/{$order->id}/{$item->id}.pdf";
                $pdf = $artwork->generate($item);
                $disk->put($pdfPath, $pdf);
                $item->update(['pdf_path' => $pdfPath]);

                Log::channel('print')->info('SubmitPrintOrder: artwork PDF generated.', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'app_product' => $item->app_product,
                    'pdf_path' => $pdfPath,
                    'bytes' => strlen($pdf),
                ]);
            } else {
                Log::channel('print')->info('SubmitPrintOrder: reusing artwork PDF from earlier attempt.', [
                    'order_id' => $order->id,
                    'item_id' => $item->id,
                    'pdf_path' => $pdfPath,
                ]);
            }

            $orderLines[] = $this->buildOrderLine(
                $item,
                MediaUrl::temporary($pdfPath, now()->addDays(self::ARTWORK_URL_TTL_DAYS)),
            );
        }

        $payload = [
            'orderLines' => $orderLines,
            'invoiceAddress' => $this->invoiceAddress(),
            'deliveryAddress' => $this->deliveryAddress($order->shipping_address),
            'deliveryMethod' => (int) config('print.delivery_method', 1),
            'reference' => "innerr-{$order->number}",
            'testOrder' => $printdeal->testOrdersEnabled(),
        ];

        // Logged before the call so every attempt records exactly what we send,
        // even when the request then fails. The artwork URL is logged in full
        // (including its presigned signature) on purpose: it is needed to fetch
        // the exact file Printdeal received when debugging an order.
        Log::channel('print')->info('SubmitPrintOrder: submitting order to Printdeal.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'payload' => $payload,
        ]);

        $response = $printdeal->createOrder($payload);

        $order->update([
            'status' => PrintOrderStatus::Submitted,
            'printdeal_order_id' => $response['uuid'] ?? null,
        ]);

        Log::channel('print')->info('SubmitPrintOrder: order placed at Printdeal.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'printdeal_order_id' => $response['uuid'] ?? null,
            'line_count' => count($orderLines),
            'test_order' => $printdeal->testOrdersEnabled(),
        ]);

        // The create response only carries the uuid; number, status, and the
        // orderline ids (needed to match status webhooks to items) come from
        // the order details. Printdeal builds the order asynchronously and 404s
        // until it is ready, so the lookup runs in its own delayed, retrying
        // job rather than blocking (and possibly re-placing) this one.
        if (isset($response['uuid'])) {
            FetchPrintdealOrderDetails::dispatch($order)->delay(now()->addSeconds(60));
        }
    }

    /**
     * Built from the snapshot taken at order creation (sku, attributes,
     * options), never from live admin config: the user paid for exactly this.
     * User options (size, color, ...) and the quantity are plain attributes
     * in v2; every item is a single piece.
     *
     * @return array<string, mixed>
     */
    private function buildOrderLine(PrintOrderItem $item, string $artworkUrl): array
    {
        $attributes = $item->printdeal_attributes;

        foreach ($item->options ?? [] as $attribute => $value) {
            $attributes[] = ['attribute' => $attribute, 'value' => $value];
        }

        return [
            'sku' => $item->printdeal_sku,
            'attributes' => PrintdealAttributes::withQuantity($attributes, 1),
            'files' => [['url' => $artworkUrl]],
            'externalId' => $item->id,
        ];
    }

    /**
     * Billing address from config, translated to the v2 field names:
     * Printdeal invoices us ('on account'), the user already paid via Mollie.
     *
     * @return array<string, string>
     */
    private function invoiceAddress(): array
    {
        return $this->toV2Address(config('print.billing_address'));
    }

    /**
     * @param  array<string, ?string>  $address
     * @return array<string, string>
     */
    private function deliveryAddress(array $address): array
    {
        return $this->toV2Address($address);
    }

    /**
     * The stored snapshots and config keep the houseNumber/postalCode naming
     * from the app's API contract; v2 expects housenumber/zipcode.
     *
     * @param  array<string, ?string>  $address
     * @return array<string, string>
     */
    private function toV2Address(array $address): array
    {
        $translated = [
            'company' => $address['company'] ?? null,
            'firstName' => $address['firstName'] ?? null,
            'lastName' => $address['lastName'] ?? null,
            'email' => $address['email'] ?? null,
            'street' => $address['street'] ?? null,
            'housenumber' => $address['houseNumber'] ?? null,
            'housenumberAddition' => $address['houseNumberAddition'] ?? null,
            'zipcode' => $address['postalCode'] ?? null,
            'city' => $address['city'] ?? null,
            'country' => $address['country'] ?? null,
        ];

        return array_filter($translated, fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('print')->error("SubmitPrintOrder: permanently failed for order {$this->printOrder->id}", [
            'order_id' => $this->printOrder->id,
            'order_number' => $this->printOrder->number,
            'message' => $exception?->getMessage(),
        ]);

        // The user has paid; flag for manual follow-up (resubmit or refund)
        // instead of silently dropping the order.
        $this->printOrder->update(['status' => PrintOrderStatus::Failed]);
    }
}
