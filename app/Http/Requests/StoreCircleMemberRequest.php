<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCircleMemberRequest extends FormRequest
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
            'username' => ['required_without:email', 'nullable', 'string', 'exists:users,username'],
            'email' => ['required_without:username', 'nullable', 'email', 'max:255'],
        ];
    }
}
