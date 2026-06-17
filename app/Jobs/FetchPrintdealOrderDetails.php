<?php

namespace App\Jobs;

use App\Models\PrintOrder;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetch a placed order's Printdeal details (order number, status, and the
 * orderline ids needed to match status webhooks to items) and store them.
 *
 * Printdeal builds the order asynchronously: right after createOrder the
 * details endpoint answers 404 ("Order creation progress is still in running.
 * If this message persists after 60 minutes, please contact support"). So this
 * runs on a delay and retries on a slow backoff for a little over an hour
 * before giving up. It is best effort: the order is already placed and the
 * status webhook also matches on the uuid we already stored, so a permanent
 * failure here never loses the order.
 */
class FetchPrintdealOrderDetails implements ShouldQueue
{
    use Queueable;

    public int $tries = 15;

    public int $timeout = 30;

    /**
     * Roughly an hour of attempts: a short ramp, then every 5 minutes. Matches
     * the 60-minute window Printdeal's 404 message describes.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 120, 180, 300, 300, 300, 300, 300, 300, 300, 300, 300, 300, 300];

    public function __construct(
        public PrintOrder $printOrder,
    ) {}

    public function handle(PrintdealClient $printdeal): void
    {
        $order = $this->printOrder->fresh(['items']);

        // Nothing to fetch (order never placed at Printdeal) or already filled
        // in by an earlier attempt or a webhook.
        if ($order === null || $order->printdeal_order_id === null || $order->printdeal_order_number !== null) {
            return;
        }

        // Throws a RequestException on the "still in progress" 404; that bubbles
        // up so the job retries on its backoff.
        $details = $printdeal->order($order->printdeal_order_id);

        // The order resource can exist before it is fully built; without a
        // number it is not ready, so retry rather than store a half record.
        if (($details['number'] ?? null) === null) {
            throw new RuntimeException("Printdeal order details not ready yet for {$order->printdeal_order_id}.");
        }

        // Response lines follow the request order, so they pair up by index.
        foreach (array_values($details['lines'] ?? []) as $index => $line) {
            $order->items[$index]?->update([
                'printdeal_item_id' => isset($line['id']) ? (string) $line['id'] : null,
                'printdeal_status' => $line['status'] ?? null,
            ]);
        }

        $order->update([
            'printdeal_order_number' => $details['number'],
            'printdeal_status' => $details['status'] ?? null,
        ]);

        Log::channel('print')->info('FetchPrintdealOrderDetails: stored Printdeal order details.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'printdeal_order_number' => $details['number'],
            'printdeal_status' => $details['status'] ?? null,
            'attempt' => $this->attempts(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::channel('print')->warning('FetchPrintdealOrderDetails: gave up fetching Printdeal order details.', [
            'order_id' => $this->printOrder->id,
            'printdeal_order_id' => $this->printOrder->printdeal_order_id,
            'message' => $exception?->getMessage(),
        ]);
    }
}
