<?php

namespace App\Http\Requests;

use App\Models\Circle;
use App\Models\Person;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:50'],
            'birthdate' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $linkedUserId = $this->input('user_id');

            if (! $this->has('user_id') || $linkedUserId === null) {
                return;
            }

            /** @var Person $person */
            $person = $this->route('person');
            $circleIds = $person->circles()->pluck('circles.id')->all();

            if ($circleIds === []) {
                return;
            }

            $matching = Circle::whereIn('id', $circleIds)
                ->where(function ($q) use ($linkedUserId) {
                    $q->where('user_id', $linkedUserId)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $linkedUserId));
                })
                ->count();

            if ($matching < count($circleIds)) {
                $v->errors()->add('user_id', __('The linked user must be a member of every circle this person belongs to.'));
            }
        });
    }
}
