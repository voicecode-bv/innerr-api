<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CircleInvitation>
 */
class CircleInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'circle_id' => Circle::factory(),
            'user_id' => User::factory(),
            'inviter_id' => User::factory(),
            'status' => InvitationStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => InvitationStatus::Accepted]);
    }

    public function declined(): static
    {
        return $this->state(['status' => InvitationStatus::Declined]);
    }
}
