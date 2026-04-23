<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Notifications\PostLiked;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class LikeController extends Controller
{
    #[OA\Get(
        path: '/api/posts/{post}/likes',
        summary: 'List likes',
        description: 'Return a paginated list of users who liked the post, newest first.',
        tags: ['Likes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of users who liked the post',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'username', type: 'string'),
                                new OA\Property(property: 'avatar', type: 'string', nullable: true),
                            ],
                        )),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function index(Post $post): JsonResponse
    {
        $likes = $post->likes()
            ->with('user:id,name,username,avatar')
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $likes->getCollection()->map(fn ($like) => [
                'id' => $like->user->id,
                'name' => $like->user->name,
                'username' => $like->user->username,
                'avatar' => MediaUrl::sign($like->user->avatar),
            ])->values(),
            'meta' => [
                'current_page' => $likes->currentPage(),
                'last_page' => $likes->lastPage(),
                'per_page' => $likes->perPage(),
                'total' => $likes->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/posts/{post}/like',
        summary: 'Like post',
        description: 'Like a post. Idempotent — liking an already-liked post has no effect.',
        tags: ['Likes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Post liked',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'liked', type: 'boolean', example: true),
                        new OA\Property(property: 'likes_count', type: 'integer', example: 5),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Cannot like your own post'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function store(Request $request, Post $post): JsonResponse
    {
        abort_if($request->user()->id === $post->user_id, 403, 'Cannot like your own post.');

        $like = $post->likes()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        $throttleKey = "notify:post-liked:{$post->id}:{$request->user()->id}";

        if ($like->wasRecentlyCreated && Cache::add($throttleKey, true, now()->addHour())) {
            $post->user->notify(new PostLiked($request->user(), $post));
        }

        return response()->json([
            'liked' => true,
            'likes_count' => $post->refresh()->likes_count,
        ], 201);
    }

    #[OA\Delete(
        path: '/api/posts/{post}/like',
        summary: 'Unlike post',
        description: 'Remove a like from a post.',
        tags: ['Likes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Like removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'liked', type: 'boolean', example: false),
                        new OA\Property(property: 'likes_count', type: 'integer', example: 4),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function destroy(Request $request, Post $post): JsonResponse
    {
        $post->likes()
            ->where('user_id', $request->user()->id)
            ->get()
            ->each
            ->delete();

        return response()->json([
            'liked' => false,
            'likes_count' => $post->refresh()->likes_count,
        ]);
    }
}
