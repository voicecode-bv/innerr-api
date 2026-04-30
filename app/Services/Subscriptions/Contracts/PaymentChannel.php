<?php

namespace App\Services\Subscriptions\Contracts;

use App\Enums\SubscriptionChannel;
use App\Models\Subscription;
use App\Services\Subscriptions\Dto\CheckoutResultDto;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\SubscriptionStatusDto;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Dto\WebhookOutcomeDto;
use Illuminate\Http\Request;

interface PaymentChannel
{
    public function identifier(): SubscriptionChannel;

    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto;

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto;

    public function handleWebhook(Request $request): WebhookOutcomeDto;

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto;

    public function cancel(Subscription $subscription): void;

    public function refundGrant(Subscription $subscription, string $transactionId): void;
}
