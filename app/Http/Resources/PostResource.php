<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin Post */
#[OA\Schema(
    schema: 'Post',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'media_url', type: 'string'),
        new OA\Property(property: 'original_media_url', type: 'string', nullable: true, description: 'Signed URL for the untouched original upload (pre-resize / pre-transcode). Only present when `is_downloadable` is true.'),
        new OA\Property(property: 'media_type', type: 'string', enum: ['image', 'video']),
        new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true, description: 'Signed URL for the video thumbnail. Only present for video posts.'),
        new OA\Property(property: 'thumbnail_small_url', type: 'string', nullable: true, description: 'Signed URL for the 300×300 grid thumbnail. Only present for image posts.'),
        new OA\Property(property: 'width', type: 'integer', nullable: true, description: 'Orientation-corrected pixel width of the primary media item. Null until processing fills it (or for legacy media not yet backfilled). Mirrors the first item in `media`.'),
        new OA\Property(property: 'height', type: 'integer', nullable: true, description: 'Orientation-corrected pixel height of the primary media item. Use together with `width` to lay out media at its natural aspect ratio.'),
        new OA\Property(property: 'media_status', type: 'string', enum: ['processing', 'ready', 'failed'], description: 'Processing status of the media. Videos start as "processing" until transcoding completes.'),
        new OA\Property(property: 'caption', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'taken_at', type: 'string', format: 'date-time', nullable: true, description: 'Capture time read from EXIF, if present.'),
        new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true, description: 'GPS latitude from EXIF, decimal degrees.'),
        new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true, description: 'GPS longitude from EXIF, decimal degrees.'),
        new OA\Property(
            property: 'media',
            type: 'array',
            description: 'All media items attached to this post, ordered by `sort_order`. Always contains at least one item. Top-level `media_url`/`media_type`/etc. mirror the first item for backward compatibility.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'sort_order', type: 'integer'),
                    new OA\Property(property: 'url', type: 'string'),
                    new OA\Property(property: 'original_url', type: 'string', nullable: true, description: 'Only present when `is_downloadable` is true.'),
                    new OA\Property(property: 'type', type: 'string', enum: ['image', 'video']),
                    new OA\Property(property: 'status', type: 'string', enum: ['processing', 'ready', 'failed']),
                    new OA\Property(property: 'thumbnail_url', type: 'string', nullable: true),
                    new OA\Property(property: 'thumbnail_small_url', type: 'string', nullable: true),
                    new OA\Property(property: 'width', type: 'integer', nullable: true, description: 'Orientation-corrected pixel width of this media item.'),
                    new OA\Property(property: 'height', type: 'integer', nullable: true, description: 'Orientation-corrected pixel height of this media item.'),
                    new OA\Property(property: 'taken_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'latitude', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'longitude', type: 'number', format: 'float', nullable: true),
                ],
            ),
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'user', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'username', type: 'string'),
            new OA\Property(property: 'avatar', type: 'string', nullable: true),
        ]),
        new OA\Property(property: 'likes_count', type: 'integer'),
        new OA\Property(
            property: 'first_visible_liker',
            type: 'object',
            nullable: true,
            description: 'Most recent liker that is visible to the authenticated viewer (member of at least one shared circle). Used for the "X and N others like this" line under the post. Null if there are no likes or no liker is visible to the viewer.',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'username', type: 'string'),
                new OA\Property(property: 'avatar', type: 'string', nullable: true),
            ],
        ),
        new OA\Property(property: 'comments_count', type: 'integer'),
        new OA\Property(property: 'is_liked', type: 'boolean'),
        new OA\Property(property: 'is_downloadable', type: 'boolean', description: 'Whether the authenticated user is allowed to download the post media. True for the post owner, and for viewers when at least one of the post\'s circles has `members_can_download` enabled.'),
        new OA\Property(property: 'comments', type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
        new OA\Property(
            property: 'persons',
            type: 'array',
            description: 'Persons tagged on the post. Visible to anyone who can see the post (persons are shared within a circle).',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'avatar_thumbnail', type: 'string', nullable: true),
                    new OA\Property(property: 'user_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'user_username', type: 'string', nullable: true, description: 'Username of the linked user account, if `user_id` is set. Use this to link to the user\'s profile.'),
                ],
            ),
        ),
        new OA\Property(
            property: 'circles',
            type: 'array',
            description: 'Circles the post is shared with. Only included when the authenticated user is the post owner.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'photo', type: 'string', nullable: true),
                ],
            ),
        ),
        new OA\Property(
            property: 'tags',
            type: 'array',
            description: 'Personal tags the post is labeled with. Only included when the authenticated user is the post owner — tags are private per user.',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                ],
            ),
        ),
    ],
)]
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = $request->user()?->id === $this->user_id;
        $isDownloadable = $isOwner || (bool) ($this->is_downloadable_via_circles ?? false);

        // The primary item (sort_order 0) is what the top-level media_url
        // mirrors, so its dimensions describe the cover the feed/grid renders.
        $primaryMedia = $this->relationLoaded('media')
            ? ($this->media->firstWhere('sort_order', 0) ?? $this->media->first())
            : null;

        $data = [
            'id' => $this->id,
            'media_url' => MediaUrl::sign($this->media_url),
            'original_media_url' => $isDownloadable
                ? MediaUrl::sign($this->resolveOriginalMediaPath())
                : null,
            'media_type' => $this->media_type,
            'thumbnail_url' => MediaUrl::sign($this->thumbnail_url),
            'thumbnail_small_url' => MediaUrl::sign($this->thumbnail_small_url),
            'width' => $primaryMedia?->width,
            'height' => $primaryMedia?->height,
            'media_status' => $this->media_status?->value ?? 'ready',
            'caption' => $this->caption,
            'location' => $this->location,
            'taken_at' => $this->taken_at,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => MediaUrl::sign($this->user->avatar),
            ],
            'likes_count' => $this->likes_count ?? 0,
            'first_visible_liker' => $this->firstVisibleLikerPayload($request->user()),
            'comments_count' => $this->comments_count ?? 0,
            'is_liked' => (bool) ($this->is_liked ?? false),
            'is_downloadable' => $isDownloadable,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn (PostMedia $m) => [
                'id' => $m->id,
                'sort_order' => $m->sort_order,
                'url' => MediaUrl::sign($m->path),
                'original_url' => $isDownloadable
                    ? MediaUrl::sign($m->original_path ?? MediaUrl::originalPath($m->path) ?? $m->path)
                    : null,
                'type' => $m->type,
                'status' => $m->status?->value ?? 'ready',
                'thumbnail_url' => MediaUrl::sign($m->thumbnail_path),
                'thumbnail_small_url' => MediaUrl::sign($m->thumbnail_small_path),
                'width' => $m->width,
                'height' => $m->height,
                'taken_at' => $m->taken_at,
                'latitude' => $m->latitude,
                'longitude' => $m->longitude,
            ])),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'persons' => $this->whenLoaded('persons', fn () => $this->persons->map(fn ($person) => [
                'id' => $person->id,
                'name' => $person->name,
                'birthdate' => $person->birthdate?->toDateString(),
                'avatar_thumbnail' => MediaUrl::sign($person->avatar_thumbnail),
                'user_id' => $person->user_id,
                'user_username' => $person->relationLoaded('user') ? $person->user?->username : null,
            ])),
        ];

        if ($isOwner) {
            $data['circles'] = $this->whenLoaded('circles', fn () => $this->circles->map(fn ($circle) => [
                'id' => $circle->id,
                'name' => $circle->name,
                'photo' => MediaUrl::sign($circle->photo),
            ]));

            $data['tags'] = $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ]));
        }

        return $data;
    }

    /**
     * @return array{id: string, name: string, username: string, avatar: ?string}|null
     */
    private function firstVisibleLikerPayload(?User $viewer): ?array
    {
        if (! $viewer) {
            return null;
        }

        $liker = $this->resource->firstVisibleLikerFor($viewer);

        if (! $liker) {
            return null;
        }

        return [
            'id' => $liker->id,
            'name' => $liker->name,
            'username' => $liker->username,
            'avatar' => MediaUrl::sign($liker->avatar),
        ];
    }

    /**
     * Bepaal het pad naar de untouched original voor `original_media_url`.
     *
     * Voor HLS-posts ligt het origineel los van het display-pad: `path` wijst
     * naar `master.m3u8`, het origineel naar `users/{uid}/originals/posts/{x}.mp4`.
     * `MediaUrl::originalPath()` weet dat niet en zou een non-existent
     * `originals/posts/hls/.../master.m3u8` produceren. Daarom prefereren we
     * `original_path` van het eerste media-item — die wordt door
     * SubmitVideoToFileFlux gezet op het echte source-pad.
     *
     * Voor legacy mp4/foto posts (geen `original_path` gevuld) blijven we de
     * bestaande heuristiek aanhouden zodat oude rijen blijven werken.
     */
    private function resolveOriginalMediaPath(): ?string
    {
        $firstMedia = $this->resource->media?->first();

        if ($firstMedia && $firstMedia->original_path) {
            return $firstMedia->original_path;
        }

        return MediaUrl::originalPath($this->media_url) ?? $this->media_url;
    }
}
