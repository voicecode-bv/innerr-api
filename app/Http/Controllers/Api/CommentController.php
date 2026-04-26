<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Notifications\CommentReplied;
use App\Notifications\PostCommented;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/posts/{post}/comments',
        summary: 'List comments',
        description: 'Return a paginated list of top-level comments for a post, newest first. Each comment includes its replies (oldest first).',
        tags: ['Comments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of comments',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Comment')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Post not found'),
        ],
    )]
    public function index(Request $request, Post $post): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $comments = $post->comments()
            ->whereNull('parent_comment_id')
            ->with([
                'user:id,name,username,avatar',
                'replies' => fn ($q) => $q->oldest()
                    ->with('user:id,name,username,avatar')
                    ->withExists(['likes as is_liked' => fn ($lq) => $lq->where('user_id', $userId)]),
            ])
            ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $userId)])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return CommentResource::collection($comments);
    }

    #[OA\Post(
        path: '/api/posts/{post}/comments',
        summary: 'Add comment',
        description: 'Add a comment to a post.',
        tags: ['Comments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['body'],
                properties: [
                    new OA\Property(property: 'body', type: 'string', maxLength: 1000, example: 'Great post!'),
                    new OA\Property(property: 'parent_comment_id', type: 'integer', nullable: true, description: 'ID of the comment being replied to'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Comment created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Comment'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Post not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCommentRequest $request, Post $post): JsonResponse
    {
        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'parent_comment_id' => $request->validated('parent_comment_id'),
            'body' => $request->validated('body'),
        ]);

        $comment->load('user:id,name,username,avatar');

        if ($comment->parent_comment_id === null) {
            if ($request->user()->id !== $post->user_id) {
                $post->user->notify(new PostCommented($request->user(), $post, $comment));
            }
        } else {
            $comment->load('parentComment.user');
            $parentAuthor = $comment->parentComment->user;

            if ($parentAuthor->id !== $request->user()->id) {
                $parentAuthor->notify(new CommentReplied($request->user(), $post, $comment, $comment->parentComment));
            }
        }

        return (new CommentResource($comment))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/comments/{comment}',
        summary: 'Delete comment',
        description: 'Delete a comment. Requires ownership.',
        tags: ['Comments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Comment deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Comment not found'),
        ],
    )]
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        $comment->delete();

        return response()->json(null, 204);
    }
}
