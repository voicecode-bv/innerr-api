<?php

namespace App\Services\Subscriptions\Channels;

use App\Enums\SubscriptionChannel;
use App\Models\Subscription;
use App\Services\Subscriptions\Contracts\PaymentChannel;
use App\Services\Subscriptions\Dto\CheckoutResultDto;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\SubscriptionStatusDto;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Dto\WebhookOutcomeDto;
use App\Services\Subscriptions\Exceptions\NotImplementedException;
use Illuminate\Http\Request;

class GoogleChannel implements PaymentChannel
{
    public function identifier(): SubscriptionChannel
    {
        return SubscriptionChannel::Google;
    }

    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto
    {
        throw new NotImplementedException('Google Play Billing verification will be implemented in Phase 3.');
    }

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto
    {
        throw new NotImplementedException('Google Play purchases happen client-side via the Billing library.');
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        throw new NotImplementedException('Pub/Sub RTDN handling will be implemented in Phase 3.');
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        throw new NotImplementedException('Play Developer API call will be implemented in Phase 3.');
    }

    public function cancel(Subscription $subscription): void
    {
        throw new NotImplementedException('Google subscriptions can only be canceled by the user via Play Store.');
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        throw new NotImplementedException('Google refunds are observed via RTDN, not initiated server-side.');
    }
}
