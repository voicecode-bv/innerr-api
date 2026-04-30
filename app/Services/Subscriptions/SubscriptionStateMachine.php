<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionStatusChanged;
use App\Models\Subscription;
use InvalidArgumentException;

class SubscriptionStateMachine
{
    /**
     * Map of event type → resulting subscription status.
     *
     * @var array<string, SubscriptionStatus>
     */
    private array $transitions = [
        'started' => SubscriptionStatus::Active,
        'renewed' => SubscriptionStatus::Active,
        'recovered' => SubscriptionStatus::Active,
        'resumed' => SubscriptionStatus::Active,
        'upgraded' => SubscriptionStatus::Active,
        'downgraded' => SubscriptionStatus::Active,
        'price_change' => SubscriptionStatus::Active,
        'entered_grace' => SubscriptionStatus::InGrace,
        'paused' => SubscriptionStatus::Paused,
        'canceled' => SubscriptionStatus::Canceled,
        'expired' => SubscriptionStatus::Expired,
        'refunded' => SubscriptionStatus::Refunded,
    ];

    /**
     * Apply a domain event to a subscription, updating its status and firing
     * the SubscriptionStatusChanged event so listeners can invalidate caches.
     */
    public function apply(Subscription $subscription, SubscriptionEventType $event): SubscriptionStatus
    {
        $target = $this->transitions[$event->value] ?? null;

        if (! $target) {
            throw new InvalidArgumentException("No transition defined for event [{$event->value}].");
        }

        $previous = $subscription->status;

        if ($previous !== $target) {
            $subscription->status = $target;

            if ($target === SubscriptionStatus::Canceled && $subscription->canceled_at === null) {
                $subscription->canceled_at = now();
                $subscription->auto_renew = false;
            }

            if (in_array($target, [SubscriptionStatus::Expired, SubscriptionStatus::Refunded], true) && $subscription->ended_at === null) {
                $subscription->ended_at = now();
                $subscription->auto_renew = false;
            }

            $subscription->save();

            SubscriptionStatusChanged::dispatch($subscription, $previous, $target, $event);
        }

        return $target;
    }

    public function targetFor(SubscriptionEventType $event): ?SubscriptionStatus
    {
        return $this->transitions[$event->value] ?? null;
    }
}
