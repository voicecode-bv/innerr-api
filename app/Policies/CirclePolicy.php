<?php

namespace App\Policies;

use App\Models\Circle;
use App\Models\User;

class CirclePolicy
{
    public function view(User $user, Circle $circle): bool
    {
        return $user->id === $circle->user_id;
    }

    public function update(User $user, Circle $circle): bool
    {
        return $user->id === $circle->user_id;
    }

    public function delete(User $user, Circle $circle): bool
    {
        return $user->id === $circle->user_id;
    }
}
