<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Http\Controllers\Controller;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\MollieChannel;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mollie\Api\Exceptions\ApiException;

class MollieCancelController extends Controller
{
    public function __invoke(
        Request $request,
        ChannelRegistry $registry,
        SubscriptionStateMachine $stateMachine,
    ): JsonResponse {
        $subscription = $request->user()
            ->subscriptions()
            ->where('channel', SubscriptionChannel::Mollie)
            ->entitled()
            ->orderByDesc('current_period_end')
            ->first();

        if (! $subscription) {
            return new JsonResponse([
                'message' => 'No active Mollie subscription found.',
            ], 404);
        }

        /** @var MollieChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Mollie);

        try {
            $channel->cancel($subscription);
        } catch (ApiException $e) {
            return new JsonResponse([
                'message' => 'Could not cancel subscription with Mollie.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $stateMachine->apply($subscription, SubscriptionEventType::Canceled);

        return new JsonResponse([
            'message' => 'Subscription canceled. Access continues until the current period ends.',
            'current_period_end' => $subscription->current_period_end,
        ]);
    }
}
