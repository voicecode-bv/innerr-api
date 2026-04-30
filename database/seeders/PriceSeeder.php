<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Models\Plan;
use App\Models\Price;
use Illuminate\Database\Seeder;

class PriceSeeder extends Seeder
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $defaultAmounts = [
        'plus' => ['monthly' => 499, 'yearly' => 4990],
        'pro' => ['monthly' => 999, 'yearly' => 9990],
    ];

    public function run(): void
    {
        foreach (['plus', 'pro'] as $slug) {
            $plan = Plan::query()->where('slug', $slug)->first();

            if (! $plan) {
                continue;
            }

            foreach (SubscriptionChannel::cases() as $channel) {
                foreach (BillingInterval::cases() as $interval) {
                    $productId = "{$plan->slug}_{$channel->value}_{$interval->value}";

                    Price::query()->updateOrCreate(
                        [
                            'channel' => $channel,
                            'channel_product_id' => $productId,
                        ],
                        [
                            'plan_id' => $plan->id,
                            'interval' => $interval,
                            'currency' => 'EUR',
                            'amount_minor' => $this->defaultAmounts[$slug][$interval->value],
                            'is_active' => $channel === SubscriptionChannel::Mollie,
                        ],
                    );
                }
            }
        }
    }
}
