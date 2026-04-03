<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,mp4,mov', 'max:51200'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }
}
