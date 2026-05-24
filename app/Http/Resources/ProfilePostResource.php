<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostMedia;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Post */
#[OA\Schema(
    schema: 'ProfilePost',
    description: 'Compact post representation for profile grids. `media_url` points to a 300×300 grid thumbnail when available, falling back to the 800×800 thumbnail and finally the full-size media URL.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'media_url', type: 'string'),
        new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
        new OA\Property(property: 'media_status', type: 'string', enum: ['processing', 'ready', 'failed']),
        new OA\Property(
            property: 'media',
            type: 'array',
            description: 'All media items attached to this post, ordered by `sort_order`. A compact subset of the full Post `media` shape, enough to render one grid tile per item. Top-level `media_url`/`media_type` mirror the first item for backward compatibility.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'type', type: 'string', enum: ['image', 'video']),
                    new OA\Property(property: 'status', type: 'string', enum: ['processing', 'ready', 'failed']),
                    new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
                    new OA\Property(property: 'thumbnail_small_url', type: 'string', nullable: true),
                ],
            ),
        ),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'likes_count', type: 'integer'),
        new OA\Property(property: 'comments_count', type: 'integer'),
        new OA\Property(property: 'is_liked', type: 'boolean'),
    ],
)]
class ProfilePostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'media_url' => MediaUrl::sign($this->thumbnail_small_url ?? $this->thumbnail_url ?? $this->media_url),
            'media_type' => $this->media_type,
            'media_status' => $this->media_status?->value ?? 'ready',
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn (PostMedia $m) => [
                'id' => $m->id,
                'url' => MediaUrl::sign($m->path),
                'type' => $m->type,
                'status' => $m->status?->value ?? 'ready',
                'thumbnail_url' => MediaUrl::sign($m->thumbnail_path),
                'thumbnail_small_url' => MediaUrl::sign($m->thumbnail_small_path),
            ])),
            'caption' => $this->caption,
            'location' => $this->location,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'likes_count' => $this->likes_count ?? 0,
            'comments_count' => $this->comments_count ?? 0,
            'is_liked' => (bool) ($this->is_liked ?? false),
        ];
    }
}
