<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $username = $this->input('username');

        if (is_string($username)) {
            $this->merge([
                'username' => $this->normalizeUsername($username),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:users'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
            'device_name' => ['required', 'string'],
        ];
    }

    private function normalizeUsername(string $username): string
    {
        $normalized = mb_strtolower($username);
        $normalized = str_replace(' ', '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9-]/', '', $normalized) ?? '';

        return $normalized !== '' ? $normalized : $username;
    }
}
