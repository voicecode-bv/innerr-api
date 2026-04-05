<?php

namespace App\Http\Resources;

use App\Models\Circle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Circle */
#[OA\Schema(
    schema: 'Circle',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'members_count', type: 'integer'),
        new OA\Property(property: 'members', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'avatar', type: 'string', nullable: true),
            ],
        )),
    ],
)]
class CircleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'members_count' => $this->members_count ?? 0,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name,
                'username' => $member->username,
                'avatar' => $member->avatar,
            ])),
            'pending_invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'username' => $invitation->user?->username,
                'created_at' => $invitation->created_at,
            ])),
        ];
    }
}
