<?php

namespace App\Rules;

use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class OwnedTag implements ValidationRule
{
    public function __construct(protected User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = Tag::where('id', $value)
            ->where('user_id', $this->user->id)
            ->exists();

        if (! $exists) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
