<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Notifications\PostLiked;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class LikeController extends Controller
{
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

        if ($like->wasRecentlyCreated) {
            $post->user->notify(new PostLiked($request->user(), $post));
        }

        return response()->json([
            'liked' => true,
            'likes_count' => $post->likes()->count(),
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
            ->delete();

        return response()->json([
            'liked' => false,
            'likes_count' => $post->likes()->count(),
        ]);
    }
}
