<?php

namespace App\Jobs;

use App\Enums\PrintOrderStatus;
use App\Models\PrintOrder;
use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintArtworkGenerator;
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
 * multiple line items. Split from the webhook request so a slow PDF render
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

        $disk = MediaUrl::disk();
        $payloadItems = [];

        foreach ($order->items as $item) {
            // Reuse PDFs from a previous attempt that failed after rendering.
            $pdfPath = $item->pdf_path;

            if ($pdfPath === null || ! $disk->exists($pdfPath)) {
                $pdfPath = "print-orders/{$order->id}/{$item->id}.pdf";
                $disk->put($pdfPath, $artwork->generate($item));
                $item->update(['pdf_path' => $pdfPath]);
            }

            $payloadItems[] = $this->buildItemPayload($order, $item, MediaUrl::sign($pdfPath));
        }

        $response = $printdeal->createOrder([
            'items' => $payloadItems,
            'billingAddress' => array_filter(
                config('print.billing_address'),
                fn ($value) => $value !== null && $value !== '',
            ),
            'reference' => "innerr-{$order->id}",
            'paymentMethod' => 'onAccount',
            'platform' => 'Own',
            'testOrder' => $printdeal->testOrdersEnabled(),
        ]);

        // Response items follow the request order, so they pair up by index.
        foreach (array_values($response['items'] ?? []) as $index => $responseItem) {
            $order->items[$index]?->update([
                'printdeal_item_id' => $responseItem['id'] ?? null,
            ]);
        }

        $order->update([
            'status' => PrintOrderStatus::Submitted,
            'printdeal_order_id' => $response['id'] ?? null,
            'printdeal_order_number' => $response['number'] ?? null,
            'printdeal_status' => $response['status'] ?? null,
        ]);
    }

    /**
     * Built from the snapshot taken at order creation (sku, attributes,
     * options), never from live admin config: the user paid for exactly this.
     * Items with user options are grouped products: the options travel as a
     * variant that also carries the quantity.
     *
     * @return array<string, mixed>
     */
    private function buildItemPayload(PrintOrder $order, PrintOrderItem $item, string $artworkUrl): array
    {
        $shippingAddress = $order->shipping_address;

        $payload = [
            'sku' => $item->printdeal_sku,
            'attributes' => $item->printdeal_attributes,
            'files' => [['url' => $artworkUrl]],
        ];

        $options = $item->options ?? [];

        if ($options !== []) {
            $variant = collect($options)
                ->map(fn (string $value, string $attribute): array => [
                    'attribute' => $attribute,
                    'value' => $value,
                ])
                ->values()
                ->all();
            $variant[] = ['attribute' => 'Quantity', 'value' => '1'];

            $payload['variants'] = [$variant];
        } else {
            $shippingAddress['quantity'] = 1;
        }

        $payload['shippingAddresses'] = [$shippingAddress];

        return $payload;
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("SubmitPrintOrder: permanently failed for order {$this->printOrder->id}", [
            'message' => $exception?->getMessage(),
        ]);

        // The user has paid; flag for manual follow-up (resubmit or refund)
        // instead of silently dropping the order.
        $this->printOrder->update(['status' => PrintOrderStatus::Failed]);
    }
}
