<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Models\User;

class SubscriptionGuard
{
    /**
     * Returns the channel of an active/in-grace subscription that lives on a
     * different channel than the one the user is trying to subscribe to. When
     * null, the user is free to proceed.
     */
    public function blockingChannel(User $user, SubscriptionChannel $targetChannel): ?SubscriptionChannel
    {
        $existing = $user->activeSubscription()->first();

        if (! $existing) {
            return null;
        }

        if ($existing->channel === $targetChannel) {
            return null;
        }

        return $existing->channel;
    }
}
