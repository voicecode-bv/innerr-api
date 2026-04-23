<?php

namespace App\Http\Resources;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->data;

        foreach ([
            'user_avatar',
            'user_avatar_thumbnail',
            'from_user_avatar',
            'from_user_avatar_thumbnail',
            'to_user_avatar',
            'to_user_avatar_thumbnail',
            'post_media_url',
            'post_thumbnail_small_url',
        ] as $key) {
            if (isset($data[$key])) {
                $data[$key] = MediaUrl::sign($data[$key]);
            }
        }

        $data = $this->decorateWithText($data);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => $data,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function decorateWithText(array $data): array
    {
        return match ($this->type) {
            'circle-ownership-transfer-requested' => [
                ...$data,
                'title' => __('Ownership transfer requested'),
                'body' => __(':name wants to transfer ":circle" to you', [
                    'name' => $data['from_user_name'] ?? '',
                    'circle' => $data['circle_name'] ?? '',
                ]),
            ],
            'circle-ownership-transfer-accepted' => [
                ...$data,
                'title' => __('Ownership transfer accepted'),
                'body' => __(':name is now the owner of :circle', [
                    'name' => $data['to_user_name'] ?? '',
                    'circle' => $data['circle_name'] ?? '',
                ]),
            ],
            'circle-ownership-transfer-declined' => [
                ...$data,
                'title' => __('Ownership transfer declined'),
                'body' => __(':name declined ownership of :circle', [
                    'name' => $data['to_user_name'] ?? '',
                    'circle' => $data['circle_name'] ?? '',
                ]),
            ],
            default => $data,
        };
    }
}
