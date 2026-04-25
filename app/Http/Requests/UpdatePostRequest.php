<?php

namespace App\Http\Requests;

use App\Models\Circle;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
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
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'circle_ids' => ['sometimes', 'array', 'min:1'],
            'circle_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail) {
                $circle = Circle::find($value);

                if (! $circle) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));

                    return;
                }

                $userId = $this->user()->id;

                if ($circle->user_id !== $userId && ! $circle->members()->where('user_id', $userId)->exists()) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', function (string $attribute, mixed $value, \Closure $fail) {
                $exists = Tag::where('id', $value)
                    ->where('user_id', $this->user()->id)
                    ->exists();

                if (! $exists) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
        ];
    }
}
