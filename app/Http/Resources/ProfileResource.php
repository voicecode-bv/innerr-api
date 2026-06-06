<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin User */
#[OA\Schema(
    schema: 'Profile',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'avatar', type: 'string', nullable: true),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'posts_count', type: 'integer'),
        new OA\Property(property: 'likes_count', type: 'integer', description: 'Likes the user has given. Only present on the authenticated user\'s own profile.'),
        new OA\Property(property: 'comments_count', type: 'integer', description: 'Comments the user has written. Only present on the authenticated user\'s own profile.'),
    ],
)]
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => MediaUrl::sign($this->avatar),
            'bio' => $this->bio,
            'created_at' => $this->created_at,
            'posts_count' => $this->posts_count ?? 0,
            // Own-activity counters drive the in-app review prompt. Only exposed
            // on the user's own profile, never for other people's profiles.
            'likes_count' => $this->when($request->user()?->id === $this->id, fn () => $this->likes_count ?? 0),
            'comments_count' => $this->when($request->user()?->id === $this->id, fn () => $this->comments_count ?? 0),
        ];
    }
}
