<?php

namespace Database\Factories;

use App\Enums\SubscriptionChannel;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionTransaction>
 */
class SubscriptionTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'user_id' => fn (array $attributes) => Subscription::find($attributes['subscription_id'])?->user_id,
            'channel' => SubscriptionChannel::Mollie,
            'external_transaction_id' => 'txn_'.fake()->unique()->uuid(),
            'kind' => SubscriptionTransaction::KIND_RENEWAL,
            'amount_minor' => 499,
            'currency' => 'EUR',
            'occurred_at' => now(),
            'payload' => null,
        ];
    }
}
