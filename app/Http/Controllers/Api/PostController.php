<?php

namespace App\Http\Controllers\Api;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Jobs\TranscodeVideo;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewCirclePost;
use App\Services\MediaUploadService;
use App\Support\ExifExtractor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
        $relations = [
            'user:id,name,username,avatar',
            'comments' => fn ($query) => $query->whereNull('parent_comment_id')->latest()
                ->with([
                    'user:id,name,username,avatar',
                    'replies' => fn ($q) => $q->oldest()
                        ->with('user:id,name,username,avatar')
                        ->withExists(['likes as is_liked' => fn ($lq) => $lq->where('user_id', $request->user()->id)]),
                ])
                ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $request->user()->id)]),
            'likes',
        ];

        if ($request->user()?->id === $post->user_id) {
            $relations[] = 'circles:id,name,photo';
            $relations[] = 'tags:id,type,name,birthdate,avatar_thumbnail';
        }

        $post->load($relations);
        $post->loadExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $request->user()->id)]);

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
                        new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Circle IDs to share the post with (user must be owner or member).'),
                        new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Optional. IDs of tags to attach to this post. Each tag must be owned by the authenticated user. Each attached tag\'s `usage_count` is incremented by 1.'),
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
        $file = $request->file('media');

        $mimeType = $file->getMimeType();
        $mediaType = str_starts_with((string) $mimeType, 'video/') ? 'video' : 'image';

        Log::info('[post-store] incoming location payload', [
            'content_type' => $request->header('Content-Type'),
            'has_latitude' => $request->has('latitude'),
            'has_longitude' => $request->has('longitude'),
            'filled_latitude' => $request->filled('latitude'),
            'filled_longitude' => $request->filled('longitude'),
            'raw_latitude' => $request->input('latitude'),
            'raw_longitude' => $request->input('longitude'),
            'raw_taken_at' => $request->input('taken_at'),
            'input_keys' => array_keys($request->all()),
        ]);

        $thumbnailPath = null;
        $thumbnailSmallPath = null;
        $mediaStatus = MediaStatus::Ready;
        $exif = ['taken_at' => null, 'latitude' => null, 'longitude' => null];

        if ($mediaType === 'video') {
            // Generate the thumbnail from the local upload before the file
            // is stored — avoids a round-trip download from storage.
            $thumbnailPath = $media->generateVideoThumbnail(
                $file->getPathname(), $request->user()->id, 'posts', isLocalPath: true,
            );

            // Store the original video directly (no transcoding yet).
            // The TranscodeVideo job will replace it with the transcoded version.
            $path = $file->store(
                "users/{$request->user()->id}/posts",
                config('filesystems.media'),
            );
            $mediaStatus = MediaStatus::Processing;
        } else {
            // Read EXIF before MediaUploadService runs — convertHeicToJpeg may
            // replace the UploadedFile, and Intervention strips EXIF on save.
            $exif = ExifExtractor::fromUploadedFile($file);

            $thumbnailPath = $media->generateImageThumbnail($file, $request->user()->id, 'posts');
            $thumbnailSmallPath = $media->generateImageThumbnail($file, $request->user()->id, 'posts', size: 150);
            $path = $media->store($file, $request->user()->id, 'posts');
        }

        $latitude = $request->validated('latitude') ?? $exif['latitude'];
        $longitude = $request->validated('longitude') ?? $exif['longitude'];

        Log::info('[post-store] resolved location', [
            'exif_latitude' => $exif['latitude'],
            'exif_longitude' => $exif['longitude'],
            'validated_latitude' => $request->validated('latitude'),
            'validated_longitude' => $request->validated('longitude'),
            'final_latitude' => $latitude,
            'final_longitude' => $longitude,
        ]);

        $post = $request->user()->posts()->create([
            'media_url' => $path,
            'media_type' => $mediaType,
            'media_status' => $mediaStatus,
            'thumbnail_url' => $thumbnailPath,
            'thumbnail_small_url' => $thumbnailSmallPath,
            'caption' => $request->validated('caption'),
            'location' => $request->validated('location'),
            'taken_at' => $request->validated('taken_at') ?? $exif['taken_at'],
            'coordinates' => ($latitude !== null && $longitude !== null)
                ? new Point((float) $latitude, (float) $longitude, Srid::WGS84->value)
                : null,
        ]);

        if ($mediaType === 'video') {
            TranscodeVideo::dispatch($post);
        }

        $circleIds = $request->validated('circle_ids');

        $post->circles()->attach($circleIds);

        if ($request->filled('tag_ids')) {
            $post->syncTags($request->validated('tag_ids'));
        }

        $recipients = User::where(function ($query) use ($circleIds) {
            $query->whereHas('memberOfCircles', fn ($q) => $q->whereIn('circles.id', $circleIds))
                ->orWhereHas('circles', fn ($q) => $q->whereIn('circles.id', $circleIds));
        })
            ->whereNot('id', $request->user()->id)
            ->get();

        Notification::send($recipients, new NewCirclePost($request->user(), $post));

        $post->load('user:id,name,username,avatar');

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/posts/{post}',
        summary: 'Update post',
        description: 'Update the caption, circles, and/or tags of a post. Requires ownership. To assign or remove tags, send `tag_ids` with the full desired set: tags absent from the array are detached (and their `usage_count` is decremented), new ones are attached (and their `usage_count` is incremented). Send `tag_ids: []` to remove all tags.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'caption', type: 'string', maxLength: 2200, nullable: true),
                    new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Circle IDs to share the post with (user must be owner or member).'),
                    new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Full desired set of tag IDs. Tags must be owned by the authenticated user. Send an empty array to detach all tags.'),
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
        }

        if ($request->has('tag_ids')) {
            $post->syncTags($request->validated('tag_ids') ?? []);
        }

        $post->load(['user:id,name,username,avatar', 'circles:id,name,photo', 'tags:id,type,name,birthdate,avatar_thumbnail']);

        return new PostResource($post);
    }

    #[OA\Delete(
        path: '/api/posts/{post}',
        summary: 'Delete post',
        description: 'Delete a post and its media. Requires ownership.',
        tags: ['Posts'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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

        $media->delete($post->media_url);
        $media->delete($post->thumbnail_url);
        $media->delete($post->thumbnail_small_url);

        $post->delete();

        return response()->json(null, 204);
    }
}
