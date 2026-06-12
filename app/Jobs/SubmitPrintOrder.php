<?php

namespace App\Jobs;

use App\Enums\PrintOrderStatus;
use App\Models\PrintOrder;
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
 * After a paid Mollie payment: generate the print-ready PDF, store it, and
 * place the order at Printdeal. Split from the webhook request so a slow
 * PDF render or a Printdeal outage never blocks the webhook response, and
 * retries get the full backoff treatment.
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
        $order = $this->printOrder->fresh();

        // Idempotent: webhooks can fire multiple times for one payment, and a
        // timed-out attempt may already have placed the Printdeal order.
        if ($order->status !== PrintOrderStatus::Paid || $order->printdeal_order_id !== null) {
            return;
        }

        // Reuse the PDF from a previous attempt that failed after rendering.
        $disk = MediaUrl::disk();
        $pdfPath = $order->pdf_path;

        if ($pdfPath === null || ! $disk->exists($pdfPath)) {
            $pdfPath = "print-orders/{$order->id}/artwork.pdf";
            $disk->put($pdfPath, $artwork->generate($order));
            $order->update(['pdf_path' => $pdfPath]);
        }

        $response = $printdeal->createOrder($this->buildPayload($order, MediaUrl::sign($pdfPath)));

        $order->update([
            'status' => PrintOrderStatus::Submitted,
            'printdeal_order_id' => $response['id'] ?? null,
            'printdeal_order_number' => $response['number'] ?? null,
            'printdeal_item_id' => $response['items'][0]['id'] ?? null,
            'printdeal_status' => $response['status'] ?? null,
        ]);
    }

    /**
     * Built from the snapshot taken at order creation (sku, attributes,
     * size), never from live admin config: the user paid for exactly this.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(PrintOrder $order, string $artworkUrl): array
    {
        $shippingAddress = $order->shipping_address;

        $item = [
            'sku' => $order->printdeal_sku,
            'attributes' => $order->printdeal_attributes,
            'files' => [['url' => $artworkUrl]],
        ];

        $size = $order->options['size'] ?? null;

        if ($size !== null) {
            // Grouped product (t-shirt): the size is a variant and carries the
            // quantity, so the shipping address must not repeat it.
            $item['variants'] = [[
                ['attribute' => 'Size', 'value' => $size],
                ['attribute' => 'Quantity', 'value' => '1'],
            ]];
        } else {
            $shippingAddress['quantity'] = 1;
        }

        $item['shippingAddresses'] = [$shippingAddress];

        return [
            'items' => [$item],
            'billingAddress' => array_filter(
                config('print.billing_address'),
                fn ($value) => $value !== null && $value !== '',
            ),
            'reference' => "innerr-{$order->id}",
            'paymentMethod' => 'onAccount',
            'platform' => 'Own',
            'testOrder' => app(PrintdealClient::class)->testOrdersEnabled(),
        ];
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
