<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'description' => fake()->sentence(),
            'tier' => 1,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 1,
            'features' => ['max_storage_gb' => 100],
            'entitlements' => ['storage_100gb'],
            'metadata' => null,
        ];
    }

    public function free(): self
    {
        return $this->state([
            'slug' => 'free',
            'name' => 'Free',
            'tier' => 0,
            'is_default' => true,
            'sort_order' => 0,
            'features' => ['max_storage_gb' => 1],
            'entitlements' => ['storage_1gb'],
        ]);
    }

    public function plus(): self
    {
        return $this->state([
            'slug' => 'plus',
            'name' => 'Plus',
            'tier' => 1,
            'is_default' => false,
            'sort_order' => 1,
            'features' => ['max_storage_gb' => 100],
            'entitlements' => ['storage_100gb'],
        ]);
    }

    public function pro(): self
    {
        return $this->state([
            'slug' => 'pro',
            'name' => 'Pro',
            'tier' => 2,
            'is_default' => false,
            'sort_order' => 2,
            'features' => ['max_storage_gb' => 1024],
            'entitlements' => ['storage_1tb'],
        ]);
    }
}
