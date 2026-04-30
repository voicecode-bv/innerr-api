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

class AppleChannel implements PaymentChannel
{
    public function identifier(): SubscriptionChannel
    {
        return SubscriptionChannel::Apple;
    }

    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto
    {
        throw new NotImplementedException('Apple verifyClientPurchase will be implemented in Phase 2.');
    }

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto
    {
        throw new NotImplementedException('Apple does not support server-side checkout; purchases happen via StoreKit.');
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        throw new NotImplementedException('Apple Server Notifications V2 handling will be implemented in Phase 2.');
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        throw new NotImplementedException('Apple App Store Server API call will be implemented in Phase 2.');
    }

    public function cancel(Subscription $subscription): void
    {
        throw new NotImplementedException('Apple subscriptions can only be canceled by the user via iOS settings.');
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        throw new NotImplementedException('Apple refunds are observed via webhooks, not initiated server-side.');
    }
}
