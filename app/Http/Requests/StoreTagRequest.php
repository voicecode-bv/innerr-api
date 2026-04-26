<?php

namespace App\Http\Requests;

use App\Enums\TagType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreTagRequest extends FormRequest
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
            'type' => ['sometimes', new Enum(TagType::class)],
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('tags', 'name')
                    ->where('user_id', $this->user()->id)
                    ->where('type', $this->input('type', TagType::Tag->value)),
            ],
            'birthdate' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->filled('birthdate') && $this->input('type') !== TagType::Person->value) {
                $v->errors()->add('birthdate', __('A birthdate may only be set on a person.'));
            }
        });
    }
}
