<?php

namespace App\Services\Subscriptions\Channels;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscriptions\Contracts\PaymentChannel;
use App\Services\Subscriptions\Dto\CheckoutResultDto;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\SubscriptionStatusDto;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Dto\WebhookOutcomeDto;
use App\Services\Subscriptions\Exceptions\NotImplementedException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;
use RuntimeException;

class MollieChannel implements PaymentChannel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private MollieApiClient $client,
        private array $config,
    ) {}

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
        $customerId = $this->ensureCustomer($dto->user);

        $payment = $this->client->payments->create([
            'amount' => [
                'currency' => $dto->price->currency,
                'value' => $this->formatAmount($dto->price->amount_minor, $dto->price->currency),
            ],
            'description' => sprintf('innerr %s — %s', $dto->price->plan?->name ?? 'subscription', $dto->price->interval?->value ?? ''),
            'redirectUrl' => $dto->redirectUrl,
            'webhookUrl' => URL::route('api.webhooks.subscriptions.mollie'),
            'sequenceType' => 'first',
            'customerId' => $customerId,
            'metadata' => [
                'user_id' => $dto->user->id,
                'price_id' => $dto->price->id,
                'plan_id' => $dto->price->plan_id,
                'interval' => $dto->price->interval?->value,
                'kind' => 'first_payment',
            ],
        ]);

        return new CheckoutResultDto(
            checkoutUrl: $payment->getCheckoutUrl() ?? '',
            channelCustomerId: $customerId,
            externalReference: $payment->id,
        );
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        $paymentId = (string) $request->input('id');

        if ($paymentId === '') {
            throw new RuntimeException('Mollie webhook payload is missing payment id.');
        }

        $payment = $this->client->payments->get($paymentId);

        return new WebhookOutcomeDto(
            channel: SubscriptionChannel::Mollie,
            type: $this->mapPaymentToEventType($payment),
            externalEventId: $paymentId,
            channelSubscriptionId: $payment->subscriptionId,
            occurredAt: $this->parseDate($payment->paidAt) ?? now(),
            payload: [
                'payment_id' => $payment->id,
                'subscription_id' => $payment->subscriptionId,
                'customer_id' => $payment->customerId,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'amount_refunded' => $payment->amountRefunded,
                'sequence_type' => $payment->sequenceType,
                'metadata' => $payment->metadata,
            ],
        );
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        if ($subscription->channel_customer_id === null) {
            throw new RuntimeException('Subscription is missing Mollie customer id.');
        }

        $customer = $this->client->customers->get($subscription->channel_customer_id);
        $mollieSub = $customer->getSubscription($subscription->channel_subscription_id);

        return new SubscriptionStatusDto(
            channel: SubscriptionChannel::Mollie,
            channelSubscriptionId: $mollieSub->id,
            status: $this->mapMollieStatus($mollieSub->status),
            channelProductId: null,
            channelCustomerId: $subscription->channel_customer_id,
            currentPeriodStart: $subscription->current_period_start,
            currentPeriodEnd: $this->parseDate($mollieSub->nextPaymentDate),
            canceledAt: $this->parseDate($mollieSub->canceledAt),
            renewsAt: $this->parseDate($mollieSub->nextPaymentDate),
            autoRenew: $mollieSub->status === 'active',
            metadata: [
                'description' => $mollieSub->description,
                'amount' => $mollieSub->amount,
                'interval' => $mollieSub->interval,
            ],
        );
    }

    public function cancel(Subscription $subscription): void
    {
        if ($subscription->channel_customer_id === null) {
            throw new RuntimeException('Subscription is missing Mollie customer id.');
        }

        $customer = $this->client->customers->get($subscription->channel_customer_id);
        $customer->cancelSubscription($subscription->channel_subscription_id);
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        $payment = $this->client->payments->get($transactionId);
        $payment->refund([
            'amount' => $payment->amount,
        ]);
    }

    /**
     * Create a Mollie subscription on the customer based on the price metadata
     * encoded into the first payment. Returns the Mollie subscription id.
     */
    public function createRecurringSubscription(User $user, Payment $firstPayment): string
    {
        if ($user->mollie_customer_id === null) {
            throw new RuntimeException('User is missing Mollie customer id; cannot create recurring subscription.');
        }

        $metadata = (array) ($firstPayment->metadata ?? []);
        $priceId = (string) ($metadata['price_id'] ?? '');
        $price = Price::query()->findOrFail($priceId);

        $customer = $this->client->customers->get($user->mollie_customer_id);

        $subscription = $customer->createSubscription([
            'amount' => [
                'currency' => $price->currency,
                'value' => $this->formatAmount($price->amount_minor, $price->currency),
            ],
            'interval' => $this->mollieIntervalString($price->interval),
            'description' => sprintf('innerr %s (%s) — user %s', $price->plan?->name ?? 'subscription', $price->interval?->value, $user->id),
            'webhookUrl' => URL::route('api.webhooks.subscriptions.mollie'),
            'metadata' => [
                'user_id' => $user->id,
                'price_id' => $price->id,
                'plan_id' => $price->plan_id,
            ],
        ]);

        return $subscription->id;
    }

    private function ensureCustomer(User $user): string
    {
        if ($user->mollie_customer_id !== null) {
            return $user->mollie_customer_id;
        }

        $customer = $this->client->customers->create([
            'name' => $user->name ?? $user->username ?? "user-{$user->id}",
            'email' => $user->email,
            'metadata' => ['user_id' => $user->id],
        ]);

        $user->forceFill(['mollie_customer_id' => $customer->id])->save();

        return $customer->id;
    }

    private function formatAmount(int $minor, string $currency): string
    {
        $decimals = strtoupper($currency) === 'JPY' ? 0 : 2;

        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    private function mollieIntervalString(?BillingInterval $interval): string
    {
        return match ($interval) {
            BillingInterval::Yearly => '12 months',
            default => '1 month',
        };
    }

    private function parseDate(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function mapPaymentToEventType(Payment $payment): SubscriptionEventType
    {
        if ($payment->isRefunded() || ($payment->amountRefunded !== null && (float) $payment->amountRefunded->value > 0)) {
            return SubscriptionEventType::Refunded;
        }

        if ($payment->isPaid()) {
            $sequenceType = $payment->sequenceType;
            $kind = (array) ($payment->metadata ?? []);

            if ($sequenceType === 'first' || ($kind['kind'] ?? null) === 'first_payment') {
                return SubscriptionEventType::Started;
            }

            return SubscriptionEventType::Renewed;
        }

        if ($payment->isFailed() || $payment->isCanceled() || $payment->isExpired()) {
            return SubscriptionEventType::EnteredGrace;
        }

        return SubscriptionEventType::PriceChange;
    }

    private function mapMollieStatus(string $status): SubscriptionStatus
    {
        return match ($status) {
            'active' => SubscriptionStatus::Active,
            'pending' => SubscriptionStatus::Pending,
            'suspended' => SubscriptionStatus::InGrace,
            'canceled' => SubscriptionStatus::Canceled,
            'completed' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Pending,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
