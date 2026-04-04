<?php

namespace App\Http\Resources;

use App\Models\CircleInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin CircleInvitation */
#[OA\Schema(
    schema: 'CircleInvitation',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'accepted', 'declined']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'circle', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'inviter', properties: [
            new OA\Property(property: 'id', type: 'integer'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ], type: 'object'),
    ],
)]
class CircleInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'circle' => $this->whenLoaded('circle', fn () => [
                'id' => $this->circle->id,
                'name' => $this->circle->name,
            ]),
            'inviter' => $this->whenLoaded('inviter', fn () => [
                'id' => $this->inviter->id,
                'name' => $this->inviter->name,
                'username' => $this->inviter->username,
                'avatar' => $this->inviter->avatar,
            ]),
        ];
    }
}
