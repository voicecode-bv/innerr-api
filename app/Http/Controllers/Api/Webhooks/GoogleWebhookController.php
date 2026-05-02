<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Enums\SubscriptionChannel;
use App\Http\Controllers\Controller;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\SubscriptionEvent;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\GoogleChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleWebhookController extends Controller
{
    public function __invoke(Request $request, ChannelRegistry $registry): JsonResponse
    {
        /** @var GoogleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Google);

        try {
            $outcome = $channel->handleWebhook($request);
        } catch (\Throwable $e) {
            return new JsonResponse(['message' => 'Invalid Pub/Sub payload.', 'error' => $e->getMessage()], 400);
        }

        if ($outcome->externalEventId === '') {
            return new JsonResponse(['message' => 'Missing message id.'], 422);
        }

        $existing = SubscriptionEvent::query()
            ->where('channel', SubscriptionChannel::Google)
            ->where('external_event_id', $outcome->externalEventId)
            ->first();

        if ($existing) {
            return new JsonResponse(['message' => 'Already processed.'], 200);
        }

        $event = SubscriptionEvent::query()->create([
            'channel' => SubscriptionChannel::Google,
            'type' => $outcome->type,
            'external_event_id' => $outcome->externalEventId,
            'occurred_at' => $outcome->occurredAt,
            'received_at' => now(),
            'payload' => $outcome->payload + ['channel_subscription_id' => $outcome->channelSubscriptionId],
        ]);

        ProcessSubscriptionEvent::dispatch($event->id);

        return new JsonResponse(['message' => 'Accepted.'], 202);
    }
}
