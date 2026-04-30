<?php

use App\Models\Plan;
use App\Models\Price;
use Database\Seeders\PlanSeeder;

it('seeds three plans with the expected entitlements', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::query()->where('slug', 'free')->value('is_default'))->toBeTrue()
        ->and(data_get(Plan::query()->where('slug', 'free')->value('features'), 'max_storage_gb'))->toBe(1)
        ->and(Plan::query()->where('slug', 'plus')->value('entitlements'))->toContain('storage_100gb')
        ->and(Plan::query()->where('slug', 'pro')->value('entitlements'))->toContain('storage_1tb');
});

it('seeds inactive prices for every paid plan and channel', function () {
    $this->seed(PlanSeeder::class);

    expect(Price::query()->count())->toBe(2 * 3 * 2)
        ->and(Price::query()->where('is_active', true)->count())->toBe(0);
});
