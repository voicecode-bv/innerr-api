<?php

namespace Database\Factories;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionEvent>
 */
class SubscriptionEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'user_id' => null,
            'channel' => SubscriptionChannel::Mollie,
            'type' => SubscriptionEventType::Started,
            'from_status' => null,
            'to_status' => null,
            'external_event_id' => 'evt_'.fake()->unique()->uuid(),
            'occurred_at' => now(),
            'received_at' => now(),
            'payload' => ['raw' => 'sample'],
            'processed_at' => null,
            'error' => null,
        ];
    }
}
