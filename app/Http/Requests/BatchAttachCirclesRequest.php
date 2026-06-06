<?php

namespace App\Http\Requests;

use App\Rules\AccessibleCircle;
use Illuminate\Foundation\Http\FormRequest;

class BatchAttachCirclesRequest extends FormRequest
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
            'post_ids' => ['required', 'array', 'min:1', 'max:100'],
            'post_ids.*' => ['uuid'],
            'circle_ids' => ['required', 'array', 'min:1', 'max:50'],
            'circle_ids.*' => ['uuid', new AccessibleCircle($this->user())],
        ];
    }
}
