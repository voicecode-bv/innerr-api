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
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'media_url', type: 'string'),
        new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
        new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true, description: 'Signed URL for the video thumbnail. Only present for video posts.'),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'user', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ]),
        new OA\Property(property: 'likes_count', type: 'integer'),
        new OA\Property(property: 'comments_count', type: 'integer'),
        new OA\Property(property: 'is_liked', type: 'boolean'),
        new OA\Property(property: 'comments', type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
        new OA\Property(
            property: 'circles',
            type: 'array',
            description: 'Circles the post is shared with. Only included when the authenticated user is the post owner.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
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
            'caption' => $this->caption,
            'location' => $this->location,
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
            'likes' => $this->whenLoaded('likes'),
        ];

        if ($request->user()?->id === $this->user_id) {
            $data['circles'] = $this->whenLoaded('circles', fn () => $this->circles->map(fn ($circle) => [
                'id' => $circle->id,
                'name' => $circle->name,
            ]));
        }

        return $data;
    }
}
