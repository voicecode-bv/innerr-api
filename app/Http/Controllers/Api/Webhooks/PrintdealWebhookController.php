<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PrintOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Printdeal v2 webhooks (order.created, orderline.status.updated). Printdeal
 * does not sign payloads, so the subscription URL embeds a shared secret as
 * a path segment; requests with a wrong token are rejected.
 */
class PrintdealWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('services.printdeal.webhook_token');

        abort_unless($expected !== '' && hash_equals($expected, $token), 403);

        $payload = $request->all();

        // The docs don't pin the payload schema, so accept the order id
        // and status under the names seen in practice and log the rest.
        // Ids may be the order uuid or the numeric v2 id, hence the
        // string-normalization and the two-column match below.
        $orderId = $this->scalarToString(
            $payload['orderId']
            ?? $payload['order_id']
            ?? $payload['orderUuid']
            ?? ($payload['order']['id'] ?? null),
        );
        $status = $payload['status']
            ?? ($payload['orderline']['status'] ?? null);
        $orderlineId = $this->scalarToString(
            $payload['orderlineId']
            ?? $payload['orderline_id']
            ?? ($payload['orderline']['id'] ?? null),
        );

        if ($orderId === null) {
            Log::channel('print')->info('Printdeal webhook without recognizable order id.', ['payload' => $payload]);

            return new JsonResponse(['message' => 'Ignored.'], 200);
        }

        $order = PrintOrder::query()
            ->where('printdeal_order_id', $orderId)
            ->orWhere('printdeal_order_number', $orderId)
            ->first();

        if ($order === null) {
            Log::channel('print')->info('Printdeal webhook for unknown order.', ['printdeal_order_id' => $orderId]);

            return new JsonResponse(['message' => 'Unknown order.'], 200);
        }

        if (is_string($status) && $status !== '') {
            // Track the line's own status when the event names one; the
            // order-level status always reflects the latest event.
            if ($orderlineId !== null) {
                $order->items()
                    ->where('printdeal_item_id', $orderlineId)
                    ->update(['printdeal_status' => $status]);
            }

            $order->update(['printdeal_status' => $status]);

            Log::channel('print')->info('Printdeal status updated.', [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'printdeal_order_id' => $orderId,
                'printdeal_orderline_id' => $orderlineId,
                'printdeal_status' => $status,
            ]);
        }

        return new JsonResponse(['message' => 'Accepted.'], 200);
    }

    private function scalarToString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
