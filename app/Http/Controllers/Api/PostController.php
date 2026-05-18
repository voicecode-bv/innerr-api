<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Jobs\SubmitVideoToFileFlux;
use App\Jobs\TranscodeVideo;
use App\Models\Person;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Notifications\NewCirclePost;
use App\Notifications\PostTagged;
use App\Services\MediaUploadService;
use App\Support\ExifExtractor;
use App\Support\UserStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;
use OpenApi\Attributes as OA;

class PostController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/posts/{post}',
        summary: 'Show post',
        description: 'Return a single post with its comments and likes. When the authenticated user is the post owner, the response also includes the `circles` the post is shared with.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Post details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Post'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function show(Request $request, Post $post): PostResource
    {
        $this->authorize('view', $post);

        $relations = [
            'user:id,name,username,avatar',
            'media',
            'persons:id,name,birthdate,avatar_thumbnail,user_id', 'persons.user:id,username',
            'comments' => fn ($query) => $query->oldest()
                ->with('user:id,name,username,avatar')
                ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $request->user()->id)]),
        ];

        if ($request->user()?->id === $post->user_id) {
            $relations[] = 'circles:id,name,photo';
            $relations[] = 'tags:id,name';
        }

        $post->load($relations);
        $post->loadExists([
            'likes as is_liked' => fn ($q) => $q->where('user_id', $request->user()->id),
            'circles as is_downloadable_via_circles' => fn ($q) => $q->where('members_can_download', true),
        ]);

        return new PostResource($post);
    }

    #[OA\Post(
        path: '/api/posts',
        summary: 'Create post',
        description: 'Create a new post with media upload.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['media', 'circle_ids'],
                    properties: [
                        new OA\Property(property: 'media', type: 'string', format: 'binary', description: 'Image or video file (jpg, png, gif, mp4, mov). Max 50MB.'),
                        new OA\Property(property: 'caption', type: 'string', maxLength: 2200, nullable: true),
                        new OA\Property(property: 'location', type: 'string', maxLength: 255, nullable: true),
                        new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Circle IDs to share the post with (user must be owner or member).'),
                        new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Optional. IDs of personal tags to attach to this post. Each tag must be owned by the authenticated user. Each attached tag\'s `usage_count` is incremented by 1.'),
                        new OA\Property(property: 'person_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Optional. IDs of persons to tag on this post. Each person must belong to at least one of the selected `circle_ids`.'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Post created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Post'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StorePostRequest $request, MediaUploadService $media): JsonResponse
    {
        $files = $this->normalizeMediaFiles($request);
        $perItemMetadata = $this->resolvePerItemMetadata($request, count($files));

        $userId = $request->user()->id;
        $processed = [];

        foreach ($files as $index => $file) {
            $processed[] = $this->processMediaFile($file, $userId, $perItemMetadata[$index], $media);
        }

        $first = $processed[0];

        $post = $request->user()->posts()->create([
            'media_url' => $first['path'],
            'media_type' => $first['type'],
            'media_status' => $first['status'],
            'thumbnail_url' => $first['thumbnail_path'],
            'thumbnail_small_url' => $first['thumbnail_small_path'],
            'caption' => $request->validated('caption'),
            'location' => $request->validated('location'),
            'taken_at' => $first['taken_at'],
            'coordinates' => $first['coordinates'],
        ]);

        foreach ($processed as $index => $data) {
            $postMedia = $post->media()->create([
                'sort_order' => $index,
                'path' => $data['path'],
                'type' => $data['type'],
                'status' => $data['status'],
                'thumbnail_path' => $data['thumbnail_path'],
                'thumbnail_small_path' => $data['thumbnail_small_path'],
                'taken_at' => $data['taken_at'],
                'coordinates' => $data['coordinates'],
            ]);

            if ($data['type'] === 'video') {
                // Feature-flagged switch: FileFlux levert HLS multi-rendition
                // (1080p/720p/480p) als die enabled is; anders blijft de oude
                // lokale ffmpeg-pipeline actief. Beide paden eindigen op
                // `status: Ready` zodat de client de transitie niet hoeft te
                // onderscheiden.
                config('services.fileflux.enabled')
                    ? SubmitVideoToFileFlux::dispatch($postMedia)
                    : TranscodeVideo::dispatch($postMedia);
            }
        }

        $circleIds = $request->validated('circle_ids');

        $post->circles()->attach($circleIds);

        if ($request->filled('tag_ids')) {
            $post->syncTags($request->validated('tag_ids'));
        }

        $newlyTaggedUserIds = [];

        if ($request->filled('person_ids')) {
            $personIds = $request->validated('person_ids');
            $post->syncPersons($personIds);

            $newlyTaggedUserIds = Person::whereIn('id', $personIds)
                ->whereNotNull('user_id')
                ->where('user_id', '!=', $request->user()->id)
                ->pluck('user_id')
                ->all();
        }

        User::where(function ($query) use ($circleIds) {
            $query->whereHas('memberOfCircles', fn ($q) => $q->whereIn('circles.id', $circleIds))
                ->orWhereHas('circles', fn ($q) => $q->whereIn('circles.id', $circleIds));
        })
            ->whereNot('id', $request->user()->id)
            ->whereNotIn('id', $newlyTaggedUserIds)
            ->chunkById(200, function ($recipients) use ($request, $post): void {
                Notification::send($recipients, new NewCirclePost($request->user(), $post));
            });

        if ($newlyTaggedUserIds !== []) {
            User::whereIn('id', $newlyTaggedUserIds)
                ->chunkById(200, function ($recipients) use ($request, $post): void {
                    Notification::send($recipients, new PostTagged($request->user(), $post));
                });
        }

        $post->load(['user:id,name,username,avatar', 'media']);

        // Verzilverde upload-sessies opruimen nu de bytes definitief in storage
        // zijn beland; GcUploadSessions vangt het anders later op.
        foreach ($request->consumedUploadTokens as $token) {
            UploadController::destroySession($token);
        }

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return list<UploadedFile>
     */
    private function normalizeMediaFiles(StorePostRequest $request): array
    {
        $files = $request->file('media');

        return is_array($files) ? array_values($files) : [$files];
    }

    /**
     * For single mode the top-level taken_at/lat/lng act as per-item metadata
     * for the only file. For array mode we use the per-index entries from
     * media_metadata (missing indices become empty arrays). Client values
     * win over server-extracted EXIF in both modes.
     *
     * @return list<array{taken_at: ?string, latitude: ?float, longitude: ?float}>
     */
    private function resolvePerItemMetadata(StorePostRequest $request, int $count): array
    {
        if (! $request->isMultiMedia()) {
            return [[
                'taken_at' => $request->validated('taken_at'),
                'latitude' => $request->validated('latitude'),
                'longitude' => $request->validated('longitude'),
            ]];
        }

        $metadata = $request->validated('media_metadata') ?? [];
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $entry = $metadata[$i] ?? [];
            $result[] = [
                'taken_at' => $entry['taken_at'] ?? null,
                'latitude' => $entry['latitude'] ?? null,
                'longitude' => $entry['longitude'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Process one uploaded file: store it, generate thumbnails, extract EXIF
     * for images. Client-provided metadata overrides extracted values.
     *
     * @param  array{taken_at: ?string, latitude: ?float, longitude: ?float}  $clientMeta
     * @return array{path: string, type: string, status: MediaStatus, thumbnail_path: ?string, thumbnail_small_path: ?string, taken_at: mixed, coordinates: ?Point}
     */
    private function processMediaFile(UploadedFile $file, string $userId, array $clientMeta, MediaUploadService $media): array
    {
        $mimeType = $file->getMimeType();
        $type = str_starts_with((string) $mimeType, 'video/') ? 'video' : 'image';

        $thumbnailPath = null;
        $thumbnailSmallPath = null;
        $status = MediaStatus::Ready;
        $extracted = ['taken_at' => null, 'latitude' => null, 'longitude' => null];

        if ($type === 'video') {
            $thumbnailPath = $media->generateVideoThumbnail(
                $file->getPathname(), $userId, 'posts', isLocalPath: true,
            );

            $path = $file->store("users/{$userId}/posts");
            UserStorage::trackPut($path);
            $status = MediaStatus::Processing;
        } else {
            // EXIF before MediaUploadService runs — convertHeicToJpeg may
            // replace the UploadedFile and Intervention strips EXIF on save.
            $extracted = ExifExtractor::fromUploadedFile($file);

            $thumbnailPath = $media->generateImageThumbnail($file, $userId, 'posts');
            $thumbnailSmallPath = $media->generateImageThumbnail($file, $userId, 'posts', size: MediaUploadService::THUMBNAIL_SIZE_SMALL);
            $path = $media->store($file, $userId, 'posts');
        }

        $latitude = $clientMeta['latitude'] ?? $extracted['latitude'];
        $longitude = $clientMeta['longitude'] ?? $extracted['longitude'];

        return [
            'path' => $path,
            'type' => $type,
            'status' => $status,
            'thumbnail_path' => $thumbnailPath,
            'thumbnail_small_path' => $thumbnailSmallPath,
            'taken_at' => $clientMeta['taken_at'] ?? $extracted['taken_at'],
            'coordinates' => ($latitude !== null && $longitude !== null)
                ? new Point((float) $latitude, (float) $longitude, Srid::WGS84->value)
                : null,
        ];
    }

    #[OA\Put(
        path: '/api/posts/{post}',
        summary: 'Update post',
        description: 'Update the caption, circles, tags, and/or persons of a post. Requires ownership. `tag_ids` and `person_ids` follow sync semantics: send the full desired set (empty array detaches all). The `usage_count` is adjusted both ways.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'caption', type: 'string', maxLength: 2200, nullable: true),
                    new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Circle IDs to share the post with (user must be owner or member).'),
                    new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Full desired set of personal tag IDs. Tags must be owned by the authenticated user. Send an empty array to detach all tags.'),
                    new OA\Property(property: 'person_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), description: 'Full desired set of person IDs. Each person must belong to at least one of the post\'s circles. Send an empty array to detach all persons.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Post updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Post'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Post not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        $this->authorize('update', $post);

        if ($request->has('caption')) {
            $post->update(['caption' => $request->validated('caption')]);
        }

        if ($request->has('circle_ids')) {
            $post->circles()->sync($request->validated('circle_ids'));
            $post->pruneNotificationsForLostAccess();
        }

        if ($request->has('tag_ids')) {
            $post->syncTags($request->validated('tag_ids') ?? []);
        }

        if ($request->has('person_ids')) {
            $personIds = $request->validated('person_ids') ?? [];
            $previousPersonIds = $post->persons()->pluck('people.id')->all();
            $post->syncPersons($personIds);

            $newPersonIds = array_values(array_diff($personIds, $previousPersonIds));

            if ($newPersonIds !== []) {
                $newlyTaggedUserIds = Person::whereIn('id', $newPersonIds)
                    ->whereNotNull('user_id')
                    ->where('user_id', '!=', $request->user()->id)
                    ->pluck('user_id')
                    ->all();

                if ($newlyTaggedUserIds !== []) {
                    User::whereIn('id', $newlyTaggedUserIds)
                        ->chunkById(200, function ($recipients) use ($request, $post): void {
                            Notification::send($recipients, new PostTagged($request->user(), $post));
                        });
                }
            }
        }

        $post->load([
            'user:id,name,username,avatar',
            'media',
            'circles:id,name,photo',
            'tags:id,name',
            'persons:id,name,birthdate,avatar_thumbnail,user_id', 'persons.user:id,username',
        ]);

        return new PostResource($post);
    }

    #[OA\Delete(
        path: '/api/posts/{post}',
        summary: 'Delete post',
        description: 'Delete a post and its media. Requires ownership.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Post deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function destroy(Request $request, Post $post, MediaUploadService $media): JsonResponse
    {
        $this->authorize('delete', $post);

        // Multi-photo: each PostMedia row has its own paths/thumbnails. The
        // DB-level FK cascade deletes the rows, but storage files must be
        // wiped explicitly. Use the relation when present, fall back to the
        // post's shadow columns for legacy rows missing the relation.
        $items = $post->media()->get();

        if ($items->isEmpty()) {
            $media->delete($post->media_url);
            $media->delete($post->thumbnail_url);
            $media->delete($post->thumbnail_small_url);
        } else {
            foreach ($items as $item) {
                $media->delete($item->path, $item->original_path);
                $media->delete($item->thumbnail_path);
                $media->delete($item->thumbnail_small_path);
            }
        }

        $post->delete();

        return response()->json(null, 204);
    }

    #[OA\Delete(
        path: '/api/posts/{post}/tagged-self',
        summary: 'Untag yourself from a post',
        description: 'Detach the authenticated user (via their linked Person) from a post they have been tagged in. Requires the user to actually be tagged. Returns the updated person list.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tag removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Post'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not tagged on this post'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function untagSelf(Request $request, Post $post): PostResource
    {
        $userId = $request->user()->id;

        $taggedPersonIds = $post->persons()
            ->where('people.user_id', $userId)
            ->pluck('people.id')
            ->all();

        abort_if($taggedPersonIds === [], 403, 'Not tagged on this post.');

        $remaining = array_values(array_diff(
            $post->persons()->pluck('people.id')->all(),
            $taggedPersonIds,
        ));

        $post->syncPersons($remaining);

        $post->load([
            'user:id,name,username,avatar',
            'media',
            'circles:id,name,photo',
            'tags:id,name',
            'persons:id,name,birthdate,avatar_thumbnail,user_id', 'persons.user:id,username',
        ]);
        $post->loadExists([
            'likes as is_liked' => fn ($q) => $q->where('user_id', $userId),
            'circles as is_downloadable_via_circles' => fn ($q) => $q->where('members_can_download', true),
        ]);

        return new PostResource($post);
    }
}
