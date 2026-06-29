<?php

namespace Database\Factories;

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Enums\OnboardingStepOutcome;
use App\Models\OnboardingStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingStep>
 */
class OnboardingStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'step' => fake()->randomElement(OnboardingStepEnum::cases()),
            'outcome' => OnboardingStepOutcome::Completed,
            'completed_at' => now(),
        ];
    }

    /**
     * The screen was opened but never advanced past.
     */
    public function reached(): self
    {
        return $this->state(fn (): array => [
            'outcome' => OnboardingStepOutcome::Reached,
            'completed_at' => null,
        ]);
    }

    /**
     * The user advanced past the screen without doing its intended action.
     */
    public function skipped(): self
    {
        return $this->state(fn (): array => [
            'outcome' => OnboardingStepOutcome::Skipped,
        ]);
    }
}
