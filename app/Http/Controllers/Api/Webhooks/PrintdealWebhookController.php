<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PrintOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Printdeal v3 webhooks (order.created, orderline.status.updated). The v3
 * beta does not sign payloads, so the subscription URL embeds a shared
 * secret as a path segment; requests with a wrong token are rejected.
 */
class PrintdealWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('services.printdeal.webhook_token');

        abort_unless($expected !== '' && hash_equals($expected, $token), 403);

        $payload = $request->all();

        // The beta docs don't pin the payload schema, so accept the order id
        // and status under the names seen in practice and log the rest.
        $orderId = $payload['orderId']
            ?? $payload['order_id']
            ?? ($payload['order']['id'] ?? null);
        $status = $payload['status']
            ?? ($payload['orderline']['status'] ?? null);
        $orderlineId = $payload['orderlineId']
            ?? $payload['orderline_id']
            ?? ($payload['orderline']['id'] ?? null);

        if (! is_string($orderId) || $orderId === '') {
            Log::info('Printdeal webhook without recognizable order id.', ['payload' => $payload]);

            return new JsonResponse(['message' => 'Ignored.'], 200);
        }

        $order = PrintOrder::query()
            ->where('printdeal_order_id', $orderId)
            ->first();

        if ($order === null) {
            Log::info('Printdeal webhook for unknown order.', ['printdeal_order_id' => $orderId]);

            return new JsonResponse(['message' => 'Unknown order.'], 200);
        }

        if (is_string($status) && $status !== '') {
            // Track the line's own status when the event names one; the
            // order-level status always reflects the latest event.
            if (is_string($orderlineId) && $orderlineId !== '') {
                $order->items()
                    ->where('printdeal_item_id', $orderlineId)
                    ->update(['printdeal_status' => $status]);
            }

            $order->update(['printdeal_status' => $status]);
        }

        return new JsonResponse(['message' => 'Accepted.'], 200);
    }
}
