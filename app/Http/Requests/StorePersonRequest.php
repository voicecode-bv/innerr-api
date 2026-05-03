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
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'circle_ids' => ['required', 'array', 'min:1'],
            'circle_ids.*' => ['uuid', 'distinct', new ManageablePersonCircle($this->user())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $linkedUserId = $this->input('user_id');
            $circleIds = array_values(array_unique((array) $this->input('circle_ids', [])));

            if ($linkedUserId === null || $circleIds === []) {
                return;
            }

            $matching = Circle::whereIn('id', $circleIds)
                ->where(function ($q) use ($linkedUserId) {
                    $q->where('user_id', $linkedUserId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $linkedUserId));
                })
                ->count();

            if ($matching < count($circleIds)) {
                $v->errors()->add('user_id', __('The linked user must be a member of every selected circle.'));
            }
        });
    }
}
