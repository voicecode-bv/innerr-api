<?php

namespace Database\Factories;

use App\Enums\TagType;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => TagType::Tag,
            'name' => fake()->unique()->word(),
        ];
    }

    public function person(): static
    {
        return $this->state(fn () => [
            'type' => TagType::Person,
            'name' => fake()->unique()->name(),
        ]);
    }
}
