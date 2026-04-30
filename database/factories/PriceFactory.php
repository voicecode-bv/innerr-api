<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Models\Plan;
use App\Models\Price;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'channel' => SubscriptionChannel::Mollie,
            'interval' => BillingInterval::Monthly,
            'currency' => 'EUR',
            'amount_minor' => 499,
            'channel_product_id' => 'plus_monthly_'.fake()->unique()->randomNumber(6),
            'is_active' => true,
            'external_metadata' => null,
        ];
    }

    public function channel(SubscriptionChannel $channel): self
    {
        return $this->state(['channel' => $channel]);
    }

    public function interval(BillingInterval $interval): self
    {
        return $this->state(['interval' => $interval]);
    }
}
