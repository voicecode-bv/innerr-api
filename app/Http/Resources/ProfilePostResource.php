<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Post */
#[OA\Schema(
    schema: 'ProfilePost',
    description: 'Compact post representation for profile grids. `media_url` points to a 150×150 grid thumbnail when available, falling back to the 400×400 thumbnail and finally the full-size media URL.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'media_url', type: 'string'),
        new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
        new OA\Property(property: 'media_status', type: 'string', enum: ['processing', 'ready', 'failed']),
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
