<?php

namespace App\Jobs\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use App\Services\Subscriptions\Channels\GoogleChannel;
use App\Services\Subscriptions\Channels\MollieChannel;
use App\Services\Subscriptions\SubscriptionStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

class ProcessSubscriptionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onQueue('subscriptions');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        $event = SubscriptionEvent::query()->find($this->eventId);
        $key = $event?->subscription_id ?? "event:{$this->eventId}";

        return [(new WithoutOverlapping((string) $key))->expireAfter(120)];
    }

    public function handle(
        ChannelRegistry $registry,
        SubscriptionStateMachine $stateMachine,
        MollieApiClient $mollie,
        AppleJwsVerifier $appleVerifier,
    ): void {
        DB::transaction(function () use ($registry, $stateMachine, $mollie, $appleVerifier): void {
            $event = SubscriptionEvent::query()->lockForUpdate()->find($this->eventId);

            if (! $event || $event->processed_at !== null) {
                return;
            }

            match ($event->channel) {
                SubscriptionChannel::Mollie => $this->processMollieEvent($event, $registry, $stateMachine, $mollie),
                SubscriptionChannel::Apple => $this->processAppleEvent($event, $registry, $stateMachine, $appleVerifier),
                SubscriptionChannel::Google => $this->processGoogleEvent($event, $registry, $stateMachine),
                default => null,
            };

            $event->update(['processed_at' => now()]);
        });
    }

    private function processMollieEvent(
        SubscriptionEvent $event,
        ChannelRegistry $registry,
        SubscriptionStateMachine $stateMachine,
        MollieApiClient $mollie,
    ): void {
        $payment = $mollie->payments->get($event->external_event_id);
        $eventType = $this->mapPayment($payment);

        $event->fill([
            'type' => $eventType,
            'occurred_at' => $payment->paidAt ? Carbon::parse($payment->paidAt) : ($event->occurred_at ?? now()),
            'payload' => array_merge($event->payload ?? [], [
                'payment_id' => $payment->id,
                'subscription_id' => $payment->subscriptionId,
                'customer_id' => $payment->customerId,
                'status' => $payment->status,
                'sequence_type' => $payment->sequenceType,
                'metadata' => $payment->metadata,
                'amount' => $payment->amount,
                'amount_refunded' => $payment->amountRefunded,
            ]),
        ]);

        if (! $payment->isPaid() && ! $this->paymentIsRefunded($payment)) {
            $event->save();

            return;
        }

        $metadata = (array) ($payment->metadata ?? []);
        $userId = (int) ($metadata['user_id'] ?? 0);

        if ($userId === 0) {
            $event->update(['error' => 'Mollie payment has no user_id metadata; cannot process.']);

            return;
        }

        $subscription = $this->resolveSubscription(
            $payment,
            $userId,
            $registry,
        );

        $event->subscription_id = $subscription->id;
        $event->user_id = $userId;
        $event->from_status = $subscription->status;

        $stateMachine->apply($subscription, $eventType);

        $event->to_status = $subscription->fresh()->status;

        $this->recordTransaction($subscription, $payment, $eventType);

        $event->save();
    }

    private function resolveSubscription(
        Payment $payment,
        int $userId,
        ChannelRegistry $registry,
    ): Subscription {
        if ($payment->subscriptionId !== null) {
            $existing = Subscription::query()
                ->where('channel', SubscriptionChannel::Mollie)
                ->where('channel_subscription_id', $payment->subscriptionId)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $metadata = (array) ($payment->metadata ?? []);
        $price = Price::query()->with('plan')->find((int) ($metadata['price_id'] ?? 0));
        $plan = $price?->plan ?? Plan::default();

        /** @var MollieChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Mollie);

        $user = User::query()->findOrFail($userId);

        $mollieSubscriptionId = $payment->subscriptionId ?? $channel->createRecurringSubscription($user, $payment);

        $now = $payment->paidAt ? Carbon::parse($payment->paidAt) : now();
        $periodEnd = (clone $now)->add($price?->interval?->value === 'yearly' ? '12 months' : '1 month');

        return Subscription::query()->create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'price_id' => $price?->id,
            'channel' => SubscriptionChannel::Mollie,
            'channel_subscription_id' => $mollieSubscriptionId,
            'channel_customer_id' => $payment->customerId,
            'status' => SubscriptionStatus::Pending,
            'environment' => 'production',
            'auto_renew' => true,
            'started_at' => $now,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'renews_at' => $periodEnd,
            'metadata' => ['mollie_first_payment_id' => $payment->id],
        ]);
    }

    private function recordTransaction(
        Subscription $subscription,
        Payment $payment,
        SubscriptionEventType $eventType,
    ): void {
        $kind = match ($eventType) {
            SubscriptionEventType::Started => SubscriptionTransaction::KIND_INITIAL,
            SubscriptionEventType::Refunded => SubscriptionTransaction::KIND_REFUND,
            default => SubscriptionTransaction::KIND_RENEWAL,
        };

        $amountValue = (float) ($payment->amount->value ?? 0);
        $currency = $payment->amount->currency ?? 'EUR';
        $minor = (int) round($amountValue * (strtoupper($currency) === 'JPY' ? 1 : 100));

        if ($eventType === SubscriptionEventType::Refunded) {
            $minor = -$minor;
        }

        SubscriptionTransaction::query()->updateOrCreate(
            ['channel' => SubscriptionChannel::Mollie, 'external_transaction_id' => $payment->id],
            [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'kind' => $kind,
                'amount_minor' => $minor,
                'currency' => $currency,
                'occurred_at' => $payment->paidAt ? Carbon::parse($payment->paidAt) : now(),
                'payload' => ['status' => $payment->status, 'sequence_type' => $payment->sequenceType],
            ],
        );
    }

    private function mapPayment(Payment $payment): SubscriptionEventType
    {
        if ($this->paymentIsRefunded($payment)) {
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

    private function paymentIsRefunded(Payment $payment): bool
    {
        if (method_exists($payment, 'isRefunded') && $payment->isRefunded()) {
            return true;
        }

        return $payment->amountRefunded !== null && (float) $payment->amountRefunded->value > 0;
    }

    private function processAppleEvent(
        SubscriptionEvent $event,
        ChannelRegistry $registry,
        SubscriptionStateMachine $stateMachine,
        AppleJwsVerifier $verifier,
    ): void {
        $signedPayload = (string) ($event->payload['signedPayload'] ?? '');

        if ($signedPayload === '') {
            $event->update(['error' => 'Apple event payload is missing signedPayload.']);

            return;
        }

        $outer = $verifier->verify($signedPayload);
        $signedTxJws = (string) ($outer['data']['signedTransactionInfo'] ?? '');
        $transaction = $signedTxJws !== '' ? $verifier->verify($signedTxJws) : [];

        $originalTransactionId = (string) ($transaction['originalTransactionId'] ?? '');
        $productId = (string) ($transaction['productId'] ?? '');
        $appAccountToken = (string) ($transaction['appAccountToken'] ?? '');

        if ($originalTransactionId === '') {
            $event->update(['error' => 'Apple transaction is missing originalTransactionId.']);

            return;
        }

        $subscription = Subscription::query()
            ->where('channel', SubscriptionChannel::Apple)
            ->where('channel_subscription_id', $originalTransactionId)
            ->first();

        if (! $subscription) {
            $userId = $this->resolveAppleUserId($appAccountToken);

            if ($userId === null) {
                $event->update(['error' => 'Could not resolve user for Apple subscription; needs verify endpoint first.']);

                return;
            }

            $price = Price::query()
                ->where('channel', SubscriptionChannel::Apple)
                ->where('channel_product_id', $productId)
                ->first();
            $plan = $price?->plan ?? Plan::default();

            $subscription = Subscription::query()->create([
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'price_id' => $price?->id,
                'channel' => SubscriptionChannel::Apple,
                'channel_subscription_id' => $originalTransactionId,
                'status' => SubscriptionStatus::Pending,
                'environment' => (string) ($transaction['environment'] ?? 'Sandbox') === 'Sandbox' ? 'sandbox' : 'production',
                'auto_renew' => true,
                'started_at' => isset($transaction['purchaseDate']) ? Carbon::createFromTimestampMs((int) $transaction['purchaseDate']) : now(),
                'current_period_start' => isset($transaction['purchaseDate']) ? Carbon::createFromTimestampMs((int) $transaction['purchaseDate']) : null,
                'current_period_end' => isset($transaction['expiresDate']) ? Carbon::createFromTimestampMs((int) $transaction['expiresDate']) : null,
                'metadata' => ['inAppOwnershipType' => $transaction['inAppOwnershipType'] ?? null],
            ]);
        }

        /** @var AppleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Apple);

        try {
            $authoritative = $channel->fetchAuthoritativeStatus($subscription);

            $subscription->fill([
                'status' => $authoritative->status,
                'auto_renew' => $authoritative->autoRenew,
                'current_period_start' => $authoritative->currentPeriodStart ?? $subscription->current_period_start,
                'current_period_end' => $authoritative->currentPeriodEnd ?? $subscription->current_period_end,
                'renews_at' => $authoritative->renewsAt,
            ])->save();
        } catch (\Throwable $e) {
            $event->update(['error' => 'Apple authoritative fetch failed: '.$e->getMessage()]);
        }

        $eventType = SubscriptionEventType::tryFrom((string) $event->type?->value) ?? SubscriptionEventType::PriceChange;
        $event->fill([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'from_status' => $subscription->getOriginal('status') instanceof SubscriptionStatus
                ? $subscription->getOriginal('status')
                : SubscriptionStatus::tryFrom((string) $subscription->getOriginal('status')),
            'occurred_at' => $event->occurred_at ?? now(),
        ])->save();

        $stateMachine->apply($subscription, $eventType);

        $event->fill(['to_status' => $subscription->fresh()->status])->save();
    }

    private function resolveAppleUserId(string $appAccountToken): ?int
    {
        if ($appAccountToken === '') {
            return null;
        }

        return User::query()->where('apple_id', $appAccountToken)->value('id');
    }

    private function processGoogleEvent(
        SubscriptionEvent $event,
        ChannelRegistry $registry,
        SubscriptionStateMachine $stateMachine,
    ): void {
        $purchaseToken = (string) ($event->payload['channel_subscription_id'] ?? '');

        if ($purchaseToken === '') {
            $event->update(['error' => 'Google event payload is missing purchaseToken.']);

            return;
        }

        $subscription = Subscription::query()
            ->where('channel', SubscriptionChannel::Google)
            ->where('channel_subscription_id', $purchaseToken)
            ->first();

        /** @var GoogleChannel $channel */
        $channel = $registry->for(SubscriptionChannel::Google);

        try {
            $authoritative = $channel->fetchAuthoritativeStatus(
                $subscription ?? $this->stubSubscription($purchaseToken),
            );
        } catch (\Throwable $e) {
            $event->update(['error' => 'Google authoritative fetch failed: '.$e->getMessage()]);

            return;
        }

        if (! $subscription) {
            $price = Price::query()
                ->where('channel', SubscriptionChannel::Google)
                ->where('channel_product_id', $authoritative->channelProductId)
                ->first();
            $plan = $price?->plan ?? Plan::default();

            $userId = $this->resolveGoogleUserId((array) ($event->payload['subscription_notification'] ?? []));

            if ($userId === null) {
                $event->update(['error' => 'Could not resolve user for Google subscription; needs verify endpoint first.']);

                return;
            }

            $subscription = Subscription::query()->create([
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'price_id' => $price?->id,
                'channel' => SubscriptionChannel::Google,
                'channel_subscription_id' => $purchaseToken,
                'status' => SubscriptionStatus::Pending,
                'environment' => $authoritative->environment,
                'auto_renew' => $authoritative->autoRenew,
                'started_at' => $authoritative->currentPeriodStart ?? now(),
                'current_period_start' => $authoritative->currentPeriodStart,
                'current_period_end' => $authoritative->currentPeriodEnd,
                'metadata' => $authoritative->metadata,
            ]);
        } else {
            $subscription->fill([
                'auto_renew' => $authoritative->autoRenew,
                'current_period_start' => $authoritative->currentPeriodStart ?? $subscription->current_period_start,
                'current_period_end' => $authoritative->currentPeriodEnd ?? $subscription->current_period_end,
                'renews_at' => $authoritative->renewsAt,
            ])->save();
        }

        $eventType = $event->type instanceof SubscriptionEventType ? $event->type : SubscriptionEventType::PriceChange;

        $event->fill([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'from_status' => $subscription->getOriginal('status') instanceof SubscriptionStatus
                ? $subscription->getOriginal('status')
                : SubscriptionStatus::tryFrom((string) $subscription->getOriginal('status')),
        ])->save();

        $stateMachine->apply($subscription, $eventType);

        $event->fill(['to_status' => $subscription->fresh()->status])->save();
    }

    /**
     * @param  array<string, mixed>  $notification
     */
    private function resolveGoogleUserId(array $notification): ?int
    {
        $obfuscated = (string) ($notification['developerPayload']['obfuscatedExternalAccountId']
            ?? $notification['obfuscatedExternalAccountId']
            ?? '');

        if ($obfuscated === '') {
            return null;
        }

        return User::query()->where('google_id', $obfuscated)->value('id');
    }

    private function stubSubscription(string $purchaseToken): Subscription
    {
        $stub = new Subscription;
        $stub->channel_subscription_id = $purchaseToken;

        return $stub;
    }
}
