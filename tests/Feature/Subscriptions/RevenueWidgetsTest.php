<?php

use App\Enums\SubscriptionChannel;
use App\Filament\Widgets\RevenueByChannel;
use App\Filament\Widgets\RevenueByMonth;
use App\Filament\Widgets\RevenueOverview;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;

beforeEach(function () {
    Plan::factory()->free()->create();
    $plus = Plan::factory()->plus()->create();
    $user = User::factory()->create();
    $this->subscription = Subscription::factory()->for($user)->for($plus)->channel(SubscriptionChannel::Mollie)->active()->create();
});

it('aggregates today/month/year totals on RevenueOverview', function () {
    SubscriptionTransaction::factory()->create([
        'subscription_id' => $this->subscription->id,
        'user_id' => $this->subscription->user_id,
        'channel' => SubscriptionChannel::Mollie,
        'kind' => 'renewal',
        'amount_minor' => 499,
        'occurred_at' => now()->subHour(),
    ]);

    SubscriptionTransaction::factory()->create([
        'subscription_id' => $this->subscription->id,
        'user_id' => $this->subscription->user_id,
        'channel' => SubscriptionChannel::Mollie,
        'kind' => 'refund',
        'amount_minor' => -200,
        'occurred_at' => now()->subDay()->subHour(),
    ]);

    $widget = new class extends RevenueOverview
    {
        public function expose(): array
        {
            return $this->getStats();
        }
    };

    $stats = $widget->expose();
    expect($stats)->toHaveCount(3);
});

it('returns 12 monthly buckets on RevenueByMonth', function () {
    SubscriptionTransaction::factory()->create([
        'subscription_id' => $this->subscription->id,
        'user_id' => $this->subscription->user_id,
        'channel' => SubscriptionChannel::Mollie,
        'amount_minor' => 999,
        'occurred_at' => now()->subMonths(2)->startOfMonth()->addDays(5),
    ]);

    $widget = new class extends RevenueByMonth
    {
        public function expose(): array
        {
            return $this->getData();
        }
    };

    $data = $widget->expose();

    expect($data['labels'])->toHaveCount(12)
        ->and($data['datasets'][0]['data'])->toHaveCount(12);
});

it('groups revenue by channel on RevenueByChannel', function () {
    SubscriptionTransaction::factory()->create([
        'subscription_id' => $this->subscription->id,
        'user_id' => $this->subscription->user_id,
        'channel' => SubscriptionChannel::Mollie,
        'amount_minor' => 1000,
        'occurred_at' => now()->subDay(),
    ]);

    $widget = new class extends RevenueByChannel
    {
        public function expose(): array
        {
            return $this->getData();
        }
    };

    $data = $widget->expose();

    expect($data['labels'])->toContain('mollie')
        ->and($data['datasets'][0]['data'])->toContain(10.0);
});
