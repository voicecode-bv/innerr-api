<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use App\Models\Plan;
use App\Models\Price;

beforeEach(function () {
    Plan::factory()->free()->create();
    $plus = Plan::factory()->plus()->create();
    Price::factory()->channel(SubscriptionChannel::Mollie)->interval(BillingInterval::Monthly)->create([
        'plan_id' => $plus->id,
        'is_active' => true,
        'channel_product_id' => 'plus_mollie_monthly_test',
    ]);
    Price::factory()->channel(SubscriptionChannel::Apple)->interval(BillingInterval::Yearly)->create([
        'plan_id' => $plus->id,
        'is_active' => true,
        'channel_product_id' => 'plus_apple_yearly_test',
    ]);
});

it('returns the public plans catalog', function () {
    $this->getJson('/api/subscription/plans')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                ['id', 'slug', 'name', 'tier', 'is_default', 'features', 'entitlements', 'prices'],
            ],
        ]);
});

it('filters prices by channel', function () {
    $response = $this->getJson('/api/subscription/plans?channel=mollie')
        ->assertOk()
        ->json('data');

    $plus = collect($response)->firstWhere('slug', 'plus');

    expect($plus['prices'])->not->toBeEmpty()
        ->and(collect($plus['prices'])->pluck('channel')->unique()->all())->toBe(['mollie']);
});

it('sorts plans by sort_order', function () {
    $slugs = collect($this->getJson('/api/subscription/plans')->json('data'))->pluck('slug')->all();

    expect($slugs)->toBe(['free', 'plus']);
});
