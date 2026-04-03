<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        return [
            'user_id' => User::factory(),
            'media_url' => fake()->imageUrl(),
            'media_type' => 'image',
            'caption' => fake()->optional()->sentence(),
            'location' => fake()->optional()->city(),
        ];
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => 'video',
            'media_url' => fake()->url().'/video.mp4',
        ]);
    }
}
