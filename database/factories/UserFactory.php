<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'avatar' => 'https://i.pravatar.cc/150?u='.fake()->unique()->numberBetween(1, 10000),
            'bio' => fake()->optional()->sentence(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('secret'),
            'avatar' => null,
            'bio' => fake()->optional()->sentence(),
            'locale' => fake()->randomElement(['en', 'nl']),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'admin' => true,
        ]);
    }
}
