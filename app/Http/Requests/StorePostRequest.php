<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
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
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,mp4,mov', 'max:51200'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'],
            'circle_ids' => ['required', 'array', 'min:1'],
            'circle_ids.*' => ['integer', Rule::exists('circles', 'id')->where('user_id', $this->user()->id)],
        ];
    }
}
