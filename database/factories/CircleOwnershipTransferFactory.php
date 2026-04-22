<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleOwnershipTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CircleOwnershipTransfer>
 */
class CircleOwnershipTransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'circle_id' => Circle::factory(),
            'from_user_id' => User::factory(),
            'to_user_id' => User::factory(),
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
