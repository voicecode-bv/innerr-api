<?php

namespace App\Policies;

use App\Models\Circle;
use App\Models\User;

class CirclePolicy
{
    public function view(User $user, Circle $circle): bool
    {
        if ($user->id === $circle->user_id) {
            return true;
        }

        return $circle->members()->whereKey($user->id)->exists();
    }

    public function update(User $user, Circle $circle): bool
    {
        return $circle->isOwnerOrAdministrator($user);
    }

    public function delete(User $user, Circle $circle): bool
    {
        return $circle->isOwnerOrAdministrator($user);
    }

    public function transferOwnership(User $user, Circle $circle): bool
    {
        return $user->id === $circle->user_id;
    }

    public function manageAdministrators(User $user, Circle $circle): bool
    {
        return $circle->isOwnerOrAdministrator($user);
    }

    public function invite(User $user, Circle $circle): bool
    {
        if ($circle->isOwnerOrAdministrator($user)) {
            return true;
        }

        if (! $circle->members_can_invite) {
            return false;
        }

        return $circle->members()->whereKey($user->id)->exists();
    }

    public function manageInviteLinks(User $user, Circle $circle): bool
    {
        return $this->invite($user, $circle);
    }
}
