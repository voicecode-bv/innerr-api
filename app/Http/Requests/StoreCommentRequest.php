<?php

namespace App\Http\Requests;

use App\Models\Comment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
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
            'body' => ['required', 'string', 'max:1000'],
            'parent_comment_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $postId = $this->route('post')?->id;

                    $exists = Comment::where('id', $value)
                        ->where('post_id', $postId)
                        ->exists();

                    if (! $exists) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }
}
