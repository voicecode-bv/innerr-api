<?php

namespace App\Http\Resources;

use App\Enums\CircleMemberRole;
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
        new OA\Property(property: 'is_administrator', type: 'boolean', description: 'Whether the authenticated user is an administrator of this circle. Administrators can do everything the owner can, except transfer ownership. The owner is not reported as administrator (use `is_owner` for that).'),
        new OA\Property(property: 'members_can_invite', type: 'boolean', description: 'Whether non-owner members are allowed to invite others to this circle.'),
        new OA\Property(property: 'members_can_view_members', type: 'boolean', description: 'Whether non-owner members are allowed to see the list of other members. When false, the `members` array only contains the owner and the authenticated viewer.'),
        new OA\Property(property: 'members_can_download', type: 'boolean', description: 'Whether members are allowed to download photos and videos shared in this circle. Surfaced on posts via the `is_downloadable` flag.'),
        new OA\Property(property: 'auto_add_new_users', type: 'boolean', description: 'When true, every newly registered user is automatically added to this circle and only the owner can post to it.'),
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
                new OA\Property(property: 'role', type: 'string', enum: ['owner', 'administrator', 'member'], description: 'Membership role. The owner is reported as `owner`.'),
            ],
        )),
        new OA\Property(
            property: 'pending_invitations',
            type: 'array',
            description: 'Pending invitations for this circle. Returned by the show endpoint to the owner, or to members when members_can_invite is true. Also returned by the index endpoint when filtered by `not_member_username`, in which case the array contains at most one invitation for that target user.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'email', type: 'string', nullable: true),
                    new OA\Property(property: 'username', type: 'string', nullable: true),
                    new OA\Property(property: 'inviter_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'can_cancel', type: 'boolean', description: 'Whether the authenticated user is allowed to cancel/withdraw this invitation.'),
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
        $authUserId = $request->user()?->id;
        $isOwner = $this->user_id === $authUserId;
        $isAdministrator = ! $isOwner
            && $authUserId !== null
            && $this->resource->resolveViewerRole($authUserId) === CircleMemberRole::Administrator->value;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_owner' => $isOwner,
            'is_administrator' => $isAdministrator,
            'members_can_invite' => $this->members_can_invite,
            'members_can_view_members' => $this->members_can_view_members,
            'members_can_download' => $this->members_can_download,
            'auto_add_new_users' => $this->auto_add_new_users,
            'photo' => MediaUrl::sign($this->photo),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'members_count' => ($this->members_count ?? 0) + 1,
            'members' => $this->whenLoaded('members', fn () => collect([$this->user, ...$this->members])
                ->filter()
                ->map(function ($member) {
                    $isOwner = $member->id === $this->user_id;
                    $role = $isOwner
                        ? 'owner'
                        : ($member->pivot?->role ?? CircleMemberRole::Member->value);

                    return [
                        'id' => $member->id,
                        'name' => $member->name,
                        'username' => $member->username,
                        'avatar' => MediaUrl::sign($member->avatar),
                        'is_owner' => $isOwner,
                        'role' => $role,
                    ];
                })
                ->values()),
            'pending_invitations' => $this->whenLoaded('invitations', function () use ($request, $isOwner, $isAdministrator) {
                $authUserId = $request->user()?->id;
                $canCancelAny = $isOwner || $isAdministrator;

                return $this->invitations->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'username' => $invitation->user?->username,
                    'inviter_id' => $invitation->inviter_id,
                    'can_cancel' => $authUserId !== null
                        && ($canCancelAny || $authUserId === $invitation->inviter_id),
                    'created_at' => $invitation->created_at,
                ]);
            }),
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
