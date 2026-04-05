<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Notifications\CommentLiked;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CommentLikeController extends Controller
{
    #[OA\Post(
        path: '/api/comments/{comment}/like',
        summary: 'Like comment',
        description: 'Like a comment. Idempotent — liking an already-liked comment has no effect.',
        tags: ['Likes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Comment liked',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'liked', type: 'boolean', example: true),
                        new OA\Property(property: 'likes_count', type: 'integer', example: 5),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Cannot like your own comment'),
            new OA\Response(response: 404, description: 'Comment not found'),
        ],
    )]
    public function store(Request $request, Comment $comment): JsonResponse
    {
        abort_if($request->user()->id === $comment->user_id, 403, 'Cannot like your own comment.');

        $like = $comment->likes()->firstOrCreate([
            'user_id' => $request->user()->id,
        ]);

        if ($like->wasRecentlyCreated) {
            $comment->user->notify(new CommentLiked($request->user(), $comment));
        }

        return response()->json([
            'liked' => true,
            'likes_count' => $comment->likes()->count(),
        ], 201);
    }

    #[OA\Delete(
        path: '/api/comments/{comment}/like',
        summary: 'Unlike comment',
        description: 'Remove a like from a comment.',
        tags: ['Likes'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 404, description: 'Comment not found'),
        ],
    )]
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $comment->likes()
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'liked' => false,
            'likes_count' => $comment->likes()->count(),
        ]);
    }
}
