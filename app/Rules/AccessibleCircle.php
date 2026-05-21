<?php

namespace App\Rules;

use App\Models\Circle;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccessibleCircle implements ValidationRule
{
    public function __construct(protected User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $circle = Circle::find($value);

        if (! $circle) {
            $fail(__('validation.exists', ['attribute' => $attribute]));

            return;
        }

        if ($circle->isOwnerOrAdministrator($this->user)) {
            return;
        }

        if ($circle->auto_add_new_users) {
            $fail(__('Only the owner or administrators can post to this circle.'));

            return;
        }

        if (! $circle->members()->whereKey($this->user->id)->exists()) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
