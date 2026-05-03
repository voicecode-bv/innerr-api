<?php

namespace App\Http\Resources;

use App\Models\CircleOwnershipTransfer;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin CircleOwnershipTransfer */
#[OA\Schema(
    schema: 'CircleOwnershipTransfer',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'accepted', 'declined']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'circle', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'from_user', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ], type: 'object'),
    ],
)]
class CircleOwnershipTransferResource extends JsonResource
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
            'from_user' => $this->whenLoaded('fromUser', fn () => [
                'id' => $this->fromUser->id,
                'name' => $this->fromUser->name,
                'username' => $this->fromUser->username,
                'avatar' => MediaUrl::sign($this->fromUser->avatar),
            ]),
        ];
    }
}
