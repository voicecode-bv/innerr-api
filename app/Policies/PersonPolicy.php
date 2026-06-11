<?php

namespace App\Policies;

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;

class PersonPolicy
{
    public function view(User $user, Person $person): bool
    {
        return $person->circles()
            ->where(function ($query) use ($user) {
                $query->where('circles.user_id', $user->id)
                    ->orWhereHas('members', fn ($q) => $q->where('users.id', $user->id));
            })
            ->exists();
    }

    public function update(User $user, Person $person): bool
    {
        // Children are managed by their parents (the creator plus explicitly
        // assigned co-parents) — deliberately independent of circle roles or
        // the members_can_invite toggle.
        if ($person->is_child) {
            return $person->isParent($user);
        }

        if ($user->id === $person->created_by_user_id) {
            return true;
        }

        return $person->circles()
            ->where('circles.user_id', $user->id)
            ->exists();
    }

    public function delete(User $user, Person $person): bool
    {
        if ($person->is_child) {
            return $person->isParent($user);
        }

        if ($user->id === $person->created_by_user_id) {
            return true;
        }

        return $person->circles()
            ->where('circles.user_id', $user->id)
            ->exists();
    }

    public function attachToCircle(User $user, Person $person, Circle $circle): bool
    {
        // Placing a child in a circle requires managing BOTH sides: being a
        // parent of the child and being allowed to add people to the circle.
        if ($person->is_child && ! $person->isParent($user)) {
            return false;
        }

        return $this->canManagePeopleIn($user, $circle);
    }

    public function detachFromCircle(User $user, Person $person, Circle $circle): bool
    {
        // Parents can pull their child out of any circle; a circle's manager
        // can always remove a person from their own circle.
        if ($person->is_child && $person->isParent($user)) {
            return true;
        }

        if ($user->id === $person->created_by_user_id) {
            return true;
        }

        return $this->canManagePeopleIn($user, $circle);
    }

    /**
     * Whether the user may add or remove the co-parents of a child.
     */
    public function manageParents(User $user, Person $person): bool
    {
        return $person->is_child && $person->isParent($user);
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
