<?php

namespace App\Policies;

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;

class PersonPolicy
{
    public function view(User $user, Person $person): bool
    {
        return $user->id === $person->created_by_user_id;
    }

    public function update(User $user, Person $person): bool
    {
        return $user->id === $person->created_by_user_id;
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->id === $person->created_by_user_id;
    }

    public function attachToCircle(User $user, Person $person, Circle $circle): bool
    {
        if ($user->id !== $person->created_by_user_id) {
            return false;
        }

        return $this->canManagePeopleIn($user, $circle);
    }

    public function detachFromCircle(User $user, Person $person, Circle $circle): bool
    {
        return $user->id === $person->created_by_user_id;
    }

    /**
     * Whether the user is allowed to add or remove people in the given circle.
     * Mirrors CirclePolicy::invite — owner always, members only when
     * `members_can_invite` is enabled.
     */
    public function canManagePeopleIn(User $user, Circle $circle): bool
    {
        if ($user->id === $circle->user_id) {
            return true;
        }

        if (! $circle->members_can_invite) {
            return false;
        }

        return $circle->members()->whereKey($user->id)->exists();
    }
}
