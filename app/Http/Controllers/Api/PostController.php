<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Support\MediaUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;
use OpenApi\Attributes as OA;

class PostController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/posts/{post}',
        summary: 'Show post',
        description: 'Return a single post with its comments and likes.',
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
            'comments' => fn ($query) => $query->oldest()
                ->with('user:id,name,username,avatar')
                ->withCount('likes')
                ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $request->user()->id)]),
            'likes',
        ];

        if ($request->user()?->id === $post->user_id) {
            $relations[] = 'circles:id,name';
        }

        $post->load($relations)->loadCount(['likes', 'comments']);

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
                        new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Circle IDs to share the post with (must be owned by the user).'),
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
    public function store(StorePostRequest $request): JsonResponse
    {
        $file = $request->file('media');
        $file = $this->convertHeicToJpeg($file);

        $path = $file->store('posts', config('filesystems.media'));

        $mimeType = $file->getMimeType();
        $mediaType = str_starts_with($mimeType, 'video/') ? 'video' : 'image';

        $post = $request->user()->posts()->create([
            'media_url' => $path,
            'media_type' => $mediaType,
            'caption' => $request->validated('caption'),
            'location' => $request->validated('location'),
        ]);

        $post->circles()->attach($request->validated('circle_ids'));

        $post->load('user:id,name,username,avatar')
            ->loadCount(['likes', 'comments']);

        return (new PostResource($post))
            ->response()
            ->setStatusCode(201);
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
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        MediaUrl::disk()->delete($post->media_url);

        $post->delete();

        return response()->json(null, 204);
    }

    private function convertHeicToJpeg(UploadedFile $file): UploadedFile
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['heic', 'heif'])) {
            return $file;
        }

        $jpegPath = tempnam(sys_get_temp_dir(), 'heic_').'.jpg';

        Image::decodePath($file->getPathname())
            ->save($jpegPath, quality: 90);

        return new UploadedFile(
            $jpegPath,
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
            'image/jpeg',
            test: true,
        );
    }
}
