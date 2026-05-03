<?php

namespace App\Http\Resources;

use App\Models\Circle;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Circle */
#[OA\Schema(
    schema: 'Circle',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'is_owner', type: 'boolean', description: 'Whether the authenticated user is the owner of this circle.'),
        new OA\Property(property: 'members_can_invite', type: 'boolean', description: 'Whether non-owner members are allowed to invite others to this circle.'),
        new OA\Property(property: 'photo', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'members_count', type: 'integer', description: 'Total number of members including the owner.'),
        new OA\Property(property: 'members', type: 'array', description: 'Members of the circle, including the owner.', items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'avatar', type: 'string', nullable: true),
                new OA\Property(property: 'is_owner', type: 'boolean'),
            ],
        )),
        new OA\Property(
            property: 'pending_invitations',
            type: 'array',
            description: 'Pending invitations for this circle. Only returned to the owner, or to members when members_can_invite is true.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'username', type: 'string', nullable: true),
                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                ],
            ),
        ),
        new OA\Property(
            property: 'pending_ownership_transfer',
            type: 'object',
            nullable: true,
            description: 'Pending ownership transfer for this circle, if any. Only returned to the current owner and the target user.',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'from_user', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'avatar', type: 'string', nullable: true),
                ], type: 'object'),
                new OA\Property(property: 'to_user', properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'avatar', type: 'string', nullable: true),
                ], type: 'object'),
            ],
        ),
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
            'is_owner' => $this->user_id === $request->user()?->id,
            'members_can_invite' => $this->members_can_invite,
            'photo' => MediaUrl::sign($this->photo),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'members_count' => ($this->members_count ?? 0) + 1,
            'members' => $this->whenLoaded('members', fn () => collect([$this->user, ...$this->members])
                ->filter()
                ->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'username' => $member->username,
                    'avatar' => MediaUrl::sign($member->avatar),
                    'is_owner' => $member->id === $this->user_id,
                ])
                ->values()),
            'pending_invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'username' => $invitation->user?->username,
                'created_at' => $invitation->created_at,
            ])),
            'pending_ownership_transfer' => $this->whenLoaded('ownershipTransfers', function () use ($request) {
                $transfer = $this->ownershipTransfers->first();

                if ($transfer === null) {
                    return null;
                }

                $userId = $request->user()?->id;

                if ($userId !== $transfer->from_user_id && $userId !== $transfer->to_user_id) {
                    return null;
                }

                return [
                    'id' => $transfer->id,
                    'created_at' => $transfer->created_at,
                    'from_user' => [
                        'id' => $transfer->fromUser->id,
                        'name' => $transfer->fromUser->name,
                        'username' => $transfer->fromUser->username,
                        'avatar' => MediaUrl::sign($transfer->fromUser->avatar),
                    ],
                    'to_user' => [
                        'id' => $transfer->toUser->id,
                        'name' => $transfer->toUser->name,
                        'username' => $transfer->toUser->username,
                        'avatar' => MediaUrl::sign($transfer->toUser->avatar),
                    ],
                ];
            }),
        ];
    }
}
