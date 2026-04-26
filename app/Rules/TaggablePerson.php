<?php

namespace App\Rules;

use App\Models\Person;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TaggablePerson implements ValidationRule
{
    /**
     * @param  array<int, int>  $circleIds  Circle IDs the post is being shared with.
     */
    public function __construct(protected User $user, protected array $circleIds) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->circleIds === []) {
            $fail(__('validation.exists', ['attribute' => $attribute]));

            return;
        }

        $exists = Person::where('id', $value)
            ->whereHas('circles', fn ($q) => $q->whereIn('circles.id', $this->circleIds))
            ->exists();

        if (! $exists) {
            $fail(__('validation.exists', ['attribute' => $attribute]));
        }
    }
}
