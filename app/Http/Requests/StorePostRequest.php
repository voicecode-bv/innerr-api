<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCircle;
use App\Rules\MaxVideoDuration;
use App\Rules\OwnedTag;
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
            'circle_ids.*' => ['integer', new AccessibleCircle($this->user())],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', new OwnedTag($this->user())],
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
