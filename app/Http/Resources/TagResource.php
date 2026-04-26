<?php

namespace App\Http\Resources;

use App\Models\Tag;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Tag */
#[OA\Schema(
    schema: 'Tag',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'type', type: 'string', enum: ['tag', 'person'], description: 'Distinguishes regular tags from person tags. Persons are stored as tags but represent people the user can attach to posts.'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true, description: 'Only meaningful for persons. Always null for regular tags.'),
        new OA\Property(property: 'avatar', type: 'string', nullable: true, description: 'Signed URL of the person\'s avatar. Always null for regular tags.'),
        new OA\Property(property: 'avatar_thumbnail', type: 'string', nullable: true, description: 'Signed URL of the 150×150 avatar thumbnail. Always null for regular tags.'),
        new OA\Property(property: 'usage_count', type: 'integer', description: 'Denormalized count of how many of the user\'s posts this tag is attached to.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'birthdate' => $this->birthdate?->toDateString(),
            'avatar' => MediaUrl::sign($this->avatar),
            'avatar_thumbnail' => MediaUrl::sign($this->avatar_thumbnail),
            'usage_count' => $this->usage_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
