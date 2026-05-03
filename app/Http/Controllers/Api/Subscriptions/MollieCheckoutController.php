<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMollieCheckoutRequest;
use App\Models\Price;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\MollieChannel;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\SubscriptionGuard;
use Illuminate\Http\JsonResponse;
use Mollie\Api\Exceptions\ApiException;

class MollieCheckoutController extends Controller
{
    public function __invoke(
        CreateMollieCheckoutRequest $request,
        ChannelRegistry $registry,
        SubscriptionGuard $guard,
    ): JsonResponse {
        $user = $request->user();
        $price = Price::query()->with('plan')->findOrFail($request->string('price_id')->toString());

        if ($blocking = $guard->blockingChannel($user, SubscriptionChannel::Mollie)) {
            return new JsonResponse([
                'message' => 'You already have an active subscription on another channel. Cancel it first to subscribe via web.',
                'error_code' => 'active_subscription_other_channel',
                'blocking_channel' => $blocking->value,
            ], 409);
        }

        /** @var MollieChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Mollie);

        try {
            $result = $channel->createCheckout(new CreateCheckoutRequest(
                user: $user,
                price: $price,
                redirectUrl: $request->string('redirect_url')->toString(),
            ));
        } catch (ApiException $e) {
            return new JsonResponse([
                'message' => 'Could not start Mollie checkout.',
                'error' => $e->getMessage(),
            ], 502);
        }

        return new JsonResponse([
            'checkout_url' => $result->checkoutUrl,
            'reference' => $result->externalReference,
        ], 201);
    }
}
