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

        if (isset($data['user_avatar'])) {
            $data['user_avatar'] = MediaUrl::sign($data['user_avatar']);
        }

        if (isset($data['from_user_avatar'])) {
            $data['from_user_avatar'] = MediaUrl::sign($data['from_user_avatar']);
        }

        if (isset($data['to_user_avatar'])) {
            $data['to_user_avatar'] = MediaUrl::sign($data['to_user_avatar']);
        }

        if (isset($data['post_media_url'])) {
            $data['post_media_url'] = MediaUrl::sign($data['post_media_url']);
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
