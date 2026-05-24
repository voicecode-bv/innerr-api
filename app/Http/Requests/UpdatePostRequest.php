<?php

namespace App\Http\Requests;

use App\Models\Post;
use App\Rules\AccessibleCircle;
use App\Rules\OwnedTag;
use App\Rules\TaggablePerson;
use Illuminate\Contracts\Validation\Validator;
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
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'circle_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'circle_ids.*' => ['uuid', new AccessibleCircle($this->user())],
            'tag_ids' => ['sometimes', 'array', 'max:50'],
            'tag_ids.*' => ['uuid', new OwnedTag($this->user())],
            'person_ids' => ['sometimes', 'array', 'max:50'],
            'person_ids.*' => ['uuid', new TaggablePerson($this->user(), $this->effectiveCircleIds())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->filled('latitude') !== $this->filled('longitude')) {
                $v->errors()->add('longitude', 'Latitude and longitude must be provided together.');
            }
        });
    }

    /**
     * The set of circle IDs the post will be in after this update — either
     * from the request when supplied, or the post's current circles.
     *
     * @return array<int, string>
     */
    private function effectiveCircleIds(): array
    {
        if ($this->has('circle_ids')) {
            return array_values(array_filter(array_map(
                fn ($id) => is_string($id) ? $id : null,
                (array) $this->input('circle_ids', [])
            )));
        }

        /** @var Post $post */
        $post = $this->route('post');

        return $post->circles()->pluck('circles.id')->all();
    }
}
