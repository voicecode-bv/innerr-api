<?php

namespace App\Http\Resources;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Comment */
#[OA\Schema(
    schema: 'Comment',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'body', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'user', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ]),
        new OA\Property(property: 'likes_count', type: 'integer'),
        new OA\Property(property: 'is_liked', type: 'boolean'),
    ],
)]
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar,
            ],
            'likes_count' => $this->likes_count ?? 0,
            'is_liked' => (bool) ($this->is_liked ?? false),
        ];
    }
}
