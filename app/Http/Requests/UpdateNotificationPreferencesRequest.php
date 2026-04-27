<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accepteer iedere snake_case-sleutel met een boolean. Onbekende sleutels
     * zijn expliciet toegestaan zodat de client nieuwe voorkeur-namen kan
     * introduceren zonder server-deploy — ze worden in de JSON-kolom opgeslagen.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return collect($this->keys())
            ->filter(fn (string $key) => $this->isAcceptableKey($key))
            ->mapWithKeys(fn (string $key) => [$key => ['required', 'boolean']])
            ->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (empty($this->validKeys())) {
                $validator->errors()->add(
                    'preferences',
                    'At least one preference must be provided.',
                );
            }

            foreach ($this->keys() as $key) {
                if (! $this->isAcceptableKey($key)) {
                    $validator->errors()->add(
                        $key,
                        'Preference key must contain only lowercase letters, numbers and underscores.',
                    );
                }
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function validKeys(): array
    {
        return collect($this->keys())
            ->filter(fn (string $key) => $this->isAcceptableKey($key))
            ->values()
            ->all();
    }

    protected function isAcceptableKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9_]+$/', $key) && strlen($key) <= 64;
    }
}
