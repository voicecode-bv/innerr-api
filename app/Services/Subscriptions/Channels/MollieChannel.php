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

class MollieChannel implements PaymentChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function identifier(): SubscriptionChannel
    {
        return SubscriptionChannel::Mollie;
    }

    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto
    {
        throw new NotImplementedException('Mollie does not use client-side purchase verification; use createCheckout instead.');
    }

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto
    {
        throw new NotImplementedException('Mollie checkout will be implemented in Phase 1.');
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        throw new NotImplementedException('Mollie webhook handling will be implemented in Phase 1.');
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        throw new NotImplementedException('Mollie status fetch will be implemented in Phase 1.');
    }

    public function cancel(Subscription $subscription): void
    {
        throw new NotImplementedException('Mollie cancel will be implemented in Phase 1.');
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        throw new NotImplementedException('Mollie refund handling will be implemented in Phase 1.');
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
