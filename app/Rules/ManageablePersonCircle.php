<?php

namespace App\Rules;

use App\Models\Circle;
use App\Models\User;
use App\Policies\PersonPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ManageablePersonCircle implements ValidationRule
{
    public function __construct(protected User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $circle = Circle::find($value);

        if (! $circle) {
            $fail(__('validation.exists', ['attribute' => $attribute]));

            return;
        }

        if (! app(PersonPolicy::class)->canManagePeopleIn($this->user, $circle)) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
