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
use App\Services\Subscriptions\ChannelRegistry;
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
    ): void {
        DB::transaction(function () use ($registry, $stateMachine, $mollie): void {
            $event = SubscriptionEvent::query()->lockForUpdate()->find($this->eventId);

            if (! $event || $event->processed_at !== null) {
                return;
            }

            if ($event->channel === SubscriptionChannel::Mollie) {
                $this->processMollieEvent($event, $registry, $stateMachine, $mollie);
            }

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
}
