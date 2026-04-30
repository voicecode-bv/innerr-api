<?php

namespace App\Services\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Services\Subscriptions\Contracts\PaymentChannel;
use InvalidArgumentException;

class ChannelRegistry
{
    /**
     * @var array<string, PaymentChannel>
     */
    private array $channels = [];

    public function register(PaymentChannel $channel): void
    {
        $this->channels[$channel->identifier()->value] = $channel;
    }

    public function for(SubscriptionChannel $channel): PaymentChannel
    {
        $instance = $this->channels[$channel->value] ?? null;

        if (! $instance) {
            throw new InvalidArgumentException("No payment channel registered for [{$channel->value}].");
        }

        return $instance;
    }

    /**
     * @return array<string, PaymentChannel>
     */
    public function all(): array
    {
        return $this->channels;
    }
}
