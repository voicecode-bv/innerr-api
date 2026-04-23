<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $hasGps = fake()->boolean(50);

        return [
            'user_id' => User::factory(),
            'media_url' => 'https://picsum.photos/seed/'.fake()->unique()->numberBetween(1, 10000).'/600/600',
            'media_type' => 'image',
            'caption' => fake()->optional(0.8)->sentence(),
            'location' => fake()->optional(0.5)->city(),
            'taken_at' => fake()->optional(0.7)->dateTimeBetween('-2 years'),
            'coordinates' => $hasGps
                ? new Point((float) fake()->latitude(), (float) fake()->longitude(), Srid::WGS84->value)
                : null,
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => 'video',
        ]);
    }
}
