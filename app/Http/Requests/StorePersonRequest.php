<?php

namespace App\Http\Requests;

use App\Models\Circle;
use App\Rules\ManageablePersonCircle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StorePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'is_child' => ['sometimes', 'boolean'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'circle_ids' => ['required', 'array', 'min:1', 'max:50'],
            'circle_ids.*' => ['uuid', 'distinct', new ManageablePersonCircle($this->user())],
            'parent_user_ids' => ['sometimes', 'array', 'max:10'],
            'parent_user_ids.*' => ['uuid', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $circleIds = array_values(array_unique((array) $this->input('circle_ids', [])));

            $linkedUserId = $this->input('user_id');

            if ($linkedUserId !== null && $circleIds !== []) {
                $matching = Circle::whereIn('id', $circleIds)
                    ->where(function ($q) use ($linkedUserId) {
                        $q->where('user_id', $linkedUserId)
                            ->orWhereHas('members', fn ($m) => $m->where('users.id', $linkedUserId));
                    })
                    ->count();

                if ($matching < count($circleIds)) {
                    $v->errors()->add('user_id', __('The linked user must be a member of every selected circle.'));
                }
            }

            // Co-parents must be able to see the child: owner or member of at
            // least one of the selected circles. Mirrors the parents endpoint.
            $parentIds = array_values(array_unique((array) $this->input('parent_user_ids', [])));

            foreach ($parentIds as $index => $parentId) {
                if (! is_string($parentId) || $circleIds === []) {
                    continue;
                }

                $sharesCircle = Circle::whereIn('id', $circleIds)
                    ->where(function ($q) use ($parentId) {
                        $q->where('user_id', $parentId)
                            ->orWhereHas('members', fn ($m) => $m->where('users.id', $parentId));
                    })
                    ->exists();

                if (! $sharesCircle) {
                    $v->errors()->add(
                        "parent_user_ids.{$index}",
                        __('This person must be a member of one of the child\'s circles.'),
                    );
                }
            }
        });
    }
}
