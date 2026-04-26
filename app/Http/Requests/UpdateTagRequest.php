<?php

namespace App\Http\Requests;

use App\Enums\TagType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTagRequest extends FormRequest
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
        $tag = $this->route('tag');

        return [
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('tags', 'name')
                    ->where('user_id', $this->user()->id)
                    ->where('type', $tag->type->value)
                    ->ignore($tag->id),
            ],
            'birthdate' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $tag = $this->route('tag');

            if ($this->filled('birthdate') && $tag->type !== TagType::Person) {
                $v->errors()->add('birthdate', __('A birthdate may only be set on a person.'));
            }
        });
    }
}
