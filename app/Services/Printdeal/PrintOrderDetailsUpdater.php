<?php

namespace App\Services\Printdeal;

use App\Models\PrintOrder;

/**
 * Applies a Printdeal order-details payload (from GET /orders/{id}) onto a
 * local order and its items. Shared by the background detail-fetch job and the
 * manual "refresh from Printdeal" action so both write the same fields the same
 * way. Lines pair to items by position (the order the create request sent
 * them); missing fields keep their current value so a partial payload never
 * wipes data already stored.
 */
class PrintOrderDetailsUpdater
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function apply(PrintOrder $order, array $details): void
    {
        $order->loadMissing('items');

        $lines = $details['lines'] ?? [];

        foreach (array_values(is_array($lines) ? $lines : []) as $index => $line) {
            $item = $order->items[$index] ?? null;

            if ($item === null || ! is_array($line)) {
                continue;
            }

            $item->update([
                'printdeal_item_id' => isset($line['id']) ? (string) $line['id'] : $item->printdeal_item_id,
                'printdeal_status' => $line['status'] ?? $item->printdeal_status,
            ]);
        }

        $order->update([
            'printdeal_order_number' => $details['number'] ?? $order->printdeal_order_number,
            'printdeal_status' => $details['status'] ?? $order->printdeal_status,
        ]);
    }
}
