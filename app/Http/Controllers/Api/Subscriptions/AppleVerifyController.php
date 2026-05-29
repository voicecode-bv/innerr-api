<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyApplePurchaseRequest;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Exceptions\PurchaseOwnershipException;
use App\Services\Subscriptions\SubscriptionGuard;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AppleVerifyController extends Controller
{
    #[OA\Post(
        path: '/api/subscription/iap/apple/verify',
        summary: 'Verify an Apple StoreKit purchase',
        description: 'Registers an Apple subscription against the authenticated user. Provide either `signed_transaction` (the StoreKit 2 JWS) or `original_transaction_id`; the server fetches the authoritative state from the App Store Server API and creates or updates the local subscription.',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'signed_transaction', type: 'string', maxLength: 8192, description: 'StoreKit 2 JWS. Required when `original_transaction_id` is absent.', nullable: true),
                    new OA\Property(property: 'original_transaction_id', type: 'string', maxLength: 255, description: 'Apple `originalTransactionId`. Required when `signed_transaction` is absent.', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Subscription created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'subscription_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string', example: 'active'),
                        new OA\Property(property: 'plan', type: 'string', example: 'plus'),
                        new OA\Property(property: 'current_period_end', type: 'string', format: 'date-time', nullable: true),
                    ],
                ),
            ),
            new OA\Response(response: 200, description: 'Subscription updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(
                response: 409,
                description: 'Active subscription exists on another channel',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'error_code', type: 'string', example: 'active_subscription_other_channel'),
                        new OA\Property(property: 'blocking_channel', type: 'string', example: 'mollie'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation failed or Apple verification rejected'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ],
    )]
    public function __invoke(
        VerifyApplePurchaseRequest $request,
        ChannelRegistry $registry,
        SubscriptionGuard $guard,
        SubscriptionStateMachine $stateMachine,
    ): JsonResponse {
        $user = $request->user();

        if ($blocking = $guard->blockingChannel($user, SubscriptionChannel::Apple)) {
            return new JsonResponse([
                'message' => 'You already have an active subscription on another channel.',
                'error_code' => 'active_subscription_other_channel',
                'blocking_channel' => $blocking->value,
            ], 409);
        }

        /** @var AppleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Apple);

        try {
            $status = $channel->verifyClientPurchase(new VerifyPurchaseRequest(
                user: $user,
                token: $request->string('signed_transaction')->toString(),
                originalTransactionId: $request->filled('original_transaction_id')
                    ? $request->string('original_transaction_id')->toString()
                    : null,
            ));
        } catch (PurchaseOwnershipException) {
            return new JsonResponse([
                'message' => 'This purchase is registered to a different account.',
                'error_code' => 'purchase_account_mismatch',
            ], 403);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'message' => 'Could not verify Apple purchase.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $price = Price::query()
            ->where('channel', SubscriptionChannel::Apple)
            ->where('channel_product_id', $status->channelProductId)
            ->first();
        $plan = $price?->plan ?? Plan::default();

        $subscription = Subscription::query()->updateOrCreate(
            [
                'channel' => SubscriptionChannel::Apple,
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
