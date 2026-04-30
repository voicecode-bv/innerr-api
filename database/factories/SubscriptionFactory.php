<?php

namespace Database\Factories;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'user_id' => User::factory(),
            'plan_id' => Plan::factory()->plus(),
            'price_id' => null,
            'channel' => SubscriptionChannel::Mollie,
            'channel_subscription_id' => 'sub_'.fake()->unique()->uuid(),
            'channel_customer_id' => 'cst_'.fake()->uuid(),
            'status' => SubscriptionStatus::Active,
            'environment' => 'production',
            'auto_renew' => true,
            'started_at' => $now,
            'current_period_start' => $now,
            'current_period_end' => $now->copy()->addMonth(),
            'renews_at' => $now->copy()->addMonth(),
            'metadata' => null,
        ];
    }

    public function active(): self
    {
        return $this->state(['status' => SubscriptionStatus::Active]);
    }

    public function inGrace(): self
    {
        return $this->state([
            'status' => SubscriptionStatus::InGrace,
            'grace_ends_at' => now()->addDays(7),
        ]);
    }

    public function expired(): self
    {
        $end = now()->subDay();

        return $this->state([
            'status' => SubscriptionStatus::Expired,
            'auto_renew' => false,
            'renews_at' => null,
            'current_period_end' => $end,
            'ended_at' => $end,
        ]);
    }

    public function canceled(): self
    {
        return $this->state([
            'status' => SubscriptionStatus::Canceled,
            'auto_renew' => false,
            'canceled_at' => now(),
            'renews_at' => null,
        ]);
    }

    public function refunded(): self
    {
        return $this->state([
            'status' => SubscriptionStatus::Refunded,
            'auto_renew' => false,
            'ended_at' => now(),
            'renews_at' => null,
        ]);
    }

    public function channel(SubscriptionChannel $channel): self
    {
        return $this->state(['channel' => $channel]);
    }
}
