<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCircle;
use App\Rules\OwnedTag;
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
            'circle_ids.*' => ['integer', new AccessibleCircle($this->user())],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', new OwnedTag($this->user())],
        ];
    }
}
