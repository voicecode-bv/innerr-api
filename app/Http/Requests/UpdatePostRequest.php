<?php

namespace App\Http\Requests;

use App\Models\Post;
use App\Rules\AccessibleCircle;
use App\Rules\OwnedTag;
use App\Rules\TaggablePerson;
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
            'person_ids' => ['sometimes', 'array'],
            'person_ids.*' => ['integer', new TaggablePerson($this->user(), $this->effectiveCircleIds())],
        ];
    }

    /**
     * The set of circle IDs the post will be in after this update — either
     * from the request when supplied, or the post's current circles.
     *
     * @return array<int, int>
     */
    private function effectiveCircleIds(): array
    {
        if ($this->has('circle_ids')) {
            return array_values(array_filter(array_map(
                fn ($id) => is_numeric($id) ? (int) $id : null,
                (array) $this->input('circle_ids', [])
            )));
        }

        /** @var Post $post */
        $post = $this->route('post');

        return $post->circles()->pluck('circles.id')->all();
    }
}
