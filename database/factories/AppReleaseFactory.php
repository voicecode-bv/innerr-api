<?php

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Models\AppRelease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppRelease>
 */
class AppReleaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => AppPlatform::Ios,
            'latest_version' => '1.0.0',
            'minimum_version' => '1.0.0',
            'store_url' => 'https://apps.apple.com/app/id0000000000',
        ];
    }

    public function android(): static
    {
        return $this->state([
            'platform' => AppPlatform::Android,
            'store_url' => 'https://play.google.com/store/apps/details?id=app.innerr',
        ]);
    }
}
