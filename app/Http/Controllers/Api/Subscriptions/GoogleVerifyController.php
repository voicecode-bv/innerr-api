<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyGooglePurchaseRequest;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\GoogleChannel;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\SubscriptionGuard;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Illuminate\Http\JsonResponse;

class GoogleVerifyController extends Controller
{
    public function __invoke(
        VerifyGooglePurchaseRequest $request,
        ChannelRegistry $registry,
        SubscriptionGuard $guard,
        SubscriptionStateMachine $stateMachine,
    ): JsonResponse {
        $user = $request->user();

        if ($blocking = $guard->blockingChannel($user, SubscriptionChannel::Google)) {
            return new JsonResponse([
                'message' => 'You already have an active subscription on another channel.',
                'error_code' => 'active_subscription_other_channel',
                'blocking_channel' => $blocking->value,
            ], 409);
        }

        /** @var GoogleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Google);

        try {
            $status = $channel->verifyClientPurchase(new VerifyPurchaseRequest(
                user: $user,
                token: $request->string('purchase_token')->toString(),
                productId: $request->string('product_id')->toString(),
            ));
        } catch (\Throwable $e) {
            return new JsonResponse([
                'message' => 'Could not verify Google Play purchase.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $price = Price::query()
            ->where('channel', SubscriptionChannel::Google)
            ->where('channel_product_id', $status->channelProductId)
            ->first();
        $plan = $price?->plan ?? Plan::default();

        $subscription = Subscription::query()->updateOrCreate(
            [
                'channel' => SubscriptionChannel::Google,
                'channel_subscription_id' => $status->channelSubscriptionId,
            ],
            [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'price_id' => $price?->id,
                'status' => $status->status,
                'environment' => $status->environment,
                'auto_renew' => $status->autoRenew,
                'current_period_start' => $status->currentPeriodStart,
                'current_period_end' => $status->currentPeriodEnd,
                'renews_at' => $status->renewsAt,
                'metadata' => $status->metadata,
                'started_at' => $status->currentPeriodStart,
            ],
        );

        if ($subscription->wasRecentlyCreated && $status->status === SubscriptionStatus::Active) {
            $stateMachine->apply($subscription, SubscriptionEventType::Started);
        }

        return new JsonResponse([
            'subscription_id' => $subscription->id,
            'status' => $subscription->status?->value,
            'plan' => $plan->slug,
            'current_period_end' => $subscription->current_period_end,
        ], $subscription->wasRecentlyCreated ? 201 : 200);
    }
}
