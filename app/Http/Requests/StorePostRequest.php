<?php

namespace App\Http\Requests;

use App\Models\Circle;
use App\Rules\MaxVideoDuration;
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
        ];
    }
}
