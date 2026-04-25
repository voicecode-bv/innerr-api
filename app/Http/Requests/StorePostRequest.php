<?php

namespace App\Http\Requests;

use App\Models\Circle;
use App\Models\Tag;
use App\Rules\MaxVideoDuration;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
            'media' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,heic,heif,mp4,mov,m4v', 'max:256000', new MaxVideoDuration(180)],
            'caption' => ['nullable', 'string', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:now', 'after:1990-01-01'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'circle_ids' => ['required', 'array', 'min:1'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $hasLat = $this->filled('latitude');
            $hasLng = $this->filled('longitude');

            if ($hasLat !== $hasLng) {
                $v->errors()->add('longitude', 'Latitude and longitude must be provided together.');
            }
        });
    }
}
