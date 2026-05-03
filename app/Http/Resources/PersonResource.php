<?php

namespace App\Http\Resources;

use App\Models\Person;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Person */
#[OA\Schema(
    schema: 'Person',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'avatar', type: 'string', nullable: true, description: 'Signed URL of the person\'s avatar.'),
        new OA\Property(property: 'avatar_thumbnail', type: 'string', nullable: true, description: 'Signed URL of the 150×150 avatar thumbnail.'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true, description: 'When set, this person is linked to a user account in the same circle(s).'),
        new OA\Property(property: 'created_by_user_id', type: 'string', format: 'uuid', description: 'The user that created this person.'),
        new OA\Property(property: 'usage_count', type: 'integer', description: 'Denormalized count of how many posts this person is attached to.'),
        new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'IDs of the circles this person belongs to. Only included when the `circles` relation is loaded.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'birthdate' => $this->birthdate?->toDateString(),
            'avatar' => MediaUrl::sign($this->avatar),
            'avatar_thumbnail' => MediaUrl::sign($this->avatar_thumbnail),
            'user_id' => $this->user_id,
            'created_by_user_id' => $this->created_by_user_id,
            'usage_count' => $this->usage_count,
            'circle_ids' => $this->whenLoaded('circles', fn () => $this->circles->pluck('id')->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
