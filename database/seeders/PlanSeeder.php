<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::query()->updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'description' => '1 GB opslag, basisfunctionaliteit.',
                'tier' => 0,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
                'features' => ['max_storage_gb' => 1],
                'entitlements' => ['storage_1gb'],
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'plus'],
            [
                'name' => 'Plus',
                'description' => '100 GB opslag.',
                'tier' => 1,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 1,
                'features' => ['max_storage_gb' => 100],
                'entitlements' => ['storage_100gb'],
            ],
        );

        Plan::query()->updateOrCreate(
            ['slug' => 'pro'],
            [
                'name' => 'Pro',
                'description' => '1 TB opslag.',
                'tier' => 2,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
                'features' => ['max_storage_gb' => 1024],
                'entitlements' => ['storage_1tb'],
            ],
        );

        $this->call(PriceSeeder::class);
    }
}
