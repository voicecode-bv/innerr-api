<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge([
                'username' => $this->normalizeUsername($this->input('username')),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'min:1', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('users')->ignore($this->user())],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'birthdate' => ['sometimes', 'nullable', 'date'],
            'donation_percentage' => ['sometimes', 'integer', 'min:0', 'max:100'],
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
