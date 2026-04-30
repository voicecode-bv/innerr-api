<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Http\Controllers\Controller;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\SubscriptionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MollieWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $paymentId = (string) $request->input('id');

        if ($paymentId === '') {
            return new JsonResponse(['message' => 'Missing payment id.'], 422);
        }

        $existing = SubscriptionEvent::query()
            ->where('channel', SubscriptionChannel::Mollie)
            ->where('external_event_id', $paymentId)
            ->first();

        if ($existing) {
            return new JsonResponse(['message' => 'Already processed.'], 200);
        }

        $event = SubscriptionEvent::query()->create([
            'channel' => SubscriptionChannel::Mollie,
            'type' => SubscriptionEventType::PriceChange,
            'external_event_id' => $paymentId,
            'received_at' => now(),
            'payload' => ['raw_id' => $paymentId, 'received_via' => 'webhook'],
        ]);

        ProcessSubscriptionEvent::dispatch($event->id);

        return new JsonResponse(['message' => 'Accepted.'], 202);
    }
}
