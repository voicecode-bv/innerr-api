<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\PrintOrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mollie\Api\MollieApiClient;

/**
 * Mollie webhook for print-order payments (separate from the subscriptions
 * webhook so the two flows can't mis-handle each other's payments). Mollie
 * only sends a payment id; the authoritative status is always re-fetched.
 */
class PrintMollieWebhookController extends Controller
{
    public function __invoke(Request $request, MollieApiClient $mollie): JsonResponse
    {
        $paymentId = (string) $request->input('id');

        if ($paymentId === '') {
            return new JsonResponse(['message' => 'Missing payment id.'], 422);
        }

        $payment = $mollie->payments->get($paymentId);
        $orderId = (string) (((array) ($payment->metadata ?? []))['print_order_id'] ?? '');

        $order = $orderId !== '' ? PrintOrder::query()->find($orderId) : null;

        if ($order === null) {
            // Acknowledge so Mollie stops retrying; nothing to do on our side.
            Log::warning('Print Mollie webhook for unknown order.', ['payment_id' => $paymentId]);

            return new JsonResponse(['message' => 'Unknown order.'], 200);
        }

        // Only ever transition away from pending_payment: repeated webhooks
        // for the same payment must not resubmit or un-cancel an order.
        if ($order->status === PrintOrderStatus::PendingPayment) {
            if ($payment->isPaid()) {
                $order->update([
                    'status' => PrintOrderStatus::Paid,
                    'mollie_payment_id' => $payment->id,
                ]);

                SubmitPrintOrder::dispatch($order);
            } elseif ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
                $order->update(['status' => PrintOrderStatus::Canceled]);
            }
        }

        return new JsonResponse(['message' => 'Accepted.'], 200);
    }
}
