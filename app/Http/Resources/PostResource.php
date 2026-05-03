<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Post */
#[OA\Schema(
    schema: 'Post',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'media_url', type: 'string'),
        new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
        new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true, description: 'Signed URL for the video thumbnail. Only present for video posts.'),
        new OA\Property(property: 'thumbnail_small_url', type: 'string', nullable: true, description: 'Signed URL for the 150×150 grid thumbnail. Only present for image posts.'),
        new OA\Property(property: 'media_status', type: 'string', enum: ['processing', 'ready', 'failed'], description: 'Processing status of the media. Videos start as "processing" until transcoding completes.'),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'taken_at', type: 'string', format: 'date-time', nullable: true, description: 'Capture time read from EXIF, if present.'),
        new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, description: 'GPS latitude from EXIF, decimal degrees.'),
        new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, description: 'GPS longitude from EXIF, decimal degrees.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'user', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ]),
        new OA\Property(property: 'likes_count', type: 'integer'),
        new OA\Property(property: 'comments_count', type: 'integer'),
        new OA\Property(property: 'is_liked', type: 'boolean'),
        new OA\Property(property: 'comments', type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
        new OA\Property(
            property: 'persons',
            type: 'array',
            description: 'Persons tagged on the post. Visible to anyone who can see the post (persons are shared within a circle).',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'avatar_thumbnail', type: 'string', nullable: true),
                    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'user_username', type: 'string', nullable: true, description: 'Username of the linked user account, if `user_id` is set. Use this to link to the user\'s profile.'),
                ],
            ),
        ),
        new OA\Property(
            property: 'circles',
            type: 'array',
            description: 'Circles the post is shared with. Only included when the authenticated user is the post owner.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'photo', type: 'string', nullable: true),
                ],
            ),
        ),
        new OA\Property(
            property: 'tags',
            type: 'array',
            description: 'Personal tags the post is labeled with. Only included when the authenticated user is the post owner — tags are private per user.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                ],
            ),
        ),
    ],
)]
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'media_url' => MediaUrl::sign($this->media_url),
            'media_type' => $this->media_type,
            'thumbnail_url' => MediaUrl::sign($this->thumbnail_url),
            'thumbnail_small_url' => MediaUrl::sign($this->thumbnail_small_url),
            'media_status' => $this->media_status?->value ?? 'ready',
            'caption' => $this->caption,
            'location' => $this->location,
            'taken_at' => $this->taken_at,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => MediaUrl::sign($this->user->avatar),
            ],
            'likes_count' => $this->likes_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,
            'is_liked' => (bool) ($this->is_liked ?? false),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'persons' => $this->whenLoaded('persons', fn () => $this->persons->map(fn ($person) => [
                'id' => $person->id,
                'name' => $person->name,
                'birthdate' => $person->birthdate?->toDateString(),
                'avatar_thumbnail' => MediaUrl::sign($person->avatar_thumbnail),
                'user_id' => $person->user_id,
                'user_username' => $person->relationLoaded('user') ? $person->user?->username : null,
            ])),
        ];

        if ($request->user()?->id === $this->user_id) {
            $data['circles'] = $this->whenLoaded('circles', fn () => $this->circles->map(fn ($circle) => [
                'id' => $circle->id,
                'name' => $circle->name,
                'photo' => MediaUrl::sign($circle->photo),
            ]));

            $data['tags'] = $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ]));
        }

        return $data;
    }
}
