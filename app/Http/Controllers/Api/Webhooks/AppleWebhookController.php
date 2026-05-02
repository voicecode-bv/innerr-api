<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\SubscriptionChannel;
use App\Http\Controllers\Controller;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\SubscriptionEvent;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppleWebhookController extends Controller
{
    public function __invoke(Request $request, ChannelRegistry $registry): JsonResponse
    {
        $signedPayload = (string) $request->input('signedPayload');

        if ($signedPayload === '') {
            return new JsonResponse(['message' => 'Missing signedPayload.'], 422);
        }

        /** @var AppleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Apple);

        try {
            $outcome = $channel->handleWebhook($request);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Invalid signedPayload.', 'error' => $e->getMessage()], 400);
        }

        if ($outcome->externalEventId === '') {
            return new JsonResponse(['message' => 'Missing notificationUUID.'], 422);
        }

        $existing = SubscriptionEvent::query()
            ->where('channel', SubscriptionChannel::Apple)
            ->where('external_event_id', $outcome->externalEventId)
            ->first();

        if ($existing) {
            return new JsonResponse(['message' => 'Already processed.'], 200);
        }

        $event = SubscriptionEvent::query()->create([
            'channel' => SubscriptionChannel::Apple,
            'type' => $outcome->type,
            'external_event_id' => $outcome->externalEventId,
            'occurred_at' => $outcome->occurredAt,
            'received_at' => now(),
            'payload' => array_merge($outcome->payload, ['signedPayload' => $signedPayload]),
        ]);

        ProcessSubscriptionEvent::dispatch($event->id);

        return new JsonResponse(['message' => 'Accepted.'], 202);
    }
}
