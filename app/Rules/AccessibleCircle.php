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

        if ($circle->user_id === $this->user->id) {
            return;
        }

        if (! $circle->members()->whereKey($this->user->id)->exists()) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
