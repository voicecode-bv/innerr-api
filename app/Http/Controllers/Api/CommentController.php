<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentReplied;
use App\Notifications\PostCommented;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use OpenApi\Attributes as OA;

class CommentController extends Controller
{
    use AuthorizesRequests;

    /**
     * Vanaf welke client-versie verwachten we dat de SPA met `is_visible`
     * markers en geredacteerde placeholders kan omgaan. Oudere clients
     * (geen X-App-Version header óf lager dan deze drempel) krijgen
     * verborgen comments stilletjes weg-gefilterd, zodat geen crashes
     * optreden op `comment.user.username` en privacy gewaarborgd blijft.
     */
    private const VISIBILITY_V2_MIN_VERSION = '1.1.0';

    #[OA\Get(
        path: '/api/posts/{post}/comments',
        summary: 'List comments',
        description: 'Return a paginated list of comments for a post, oldest first.',
        tags: ['Comments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
        $this->authorize('view', $post);

        $userId = $request->user()->id;

        // Circles waar zowel de viewer als de post lid van zijn — de set
        // waarbinnen een comment-author "gedeeld" mag zijn met de viewer.
        // Een user "is in" een circle als ze owner (circles.user_id) zijn
        // of als member geattached zijn (circle_user pivot). Owners zitten
        // niet automatisch in circle_user, dus beide paden moeten gechecked.
        $sharedCircleIds = $post->circles()
            ->where(function ($q) use ($userId) {
                $q->where('circles.user_id', $userId)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $userId));
            })
            ->pluck('circles.id');

        $clientVersion = (string) $request->header('X-App-Version', '0.0.0');
        $supportsVisibilityMarkers = version_compare(
            $clientVersion,
            self::VISIBILITY_V2_MIN_VERSION,
            '>='
        );

        $base = $post->comments()
            ->with('user:id,name,username,avatar')
            ->withCount('likes')
            ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $userId)])
            ->oldest();

        // Oude clients (geen header of versie < drempel) krijgen geen placeholders
        // terug, want hun Comment-interface verwacht een `user`-veld. We filteren
        // verborgen comments dus weg op query-niveau (single SQL roundtrip, geen
        // gaps in pagination). Privacy blijft gewaarborgd.
        if (! $supportsVisibilityMarkers) {
            $base->where(function ($q) use ($userId, $sharedCircleIds) {
                $q->where('user_id', $userId);

                if ($sharedCircleIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', function ($sub) use ($sharedCircleIds) {
                        // Members van gedeelde circles
                        $sub->select('user_id')
                            ->from('circle_user')
                            ->whereIn('circle_id', $sharedCircleIds);
                    })->orWhereIn('user_id', function ($sub) use ($sharedCircleIds) {
                        // Owners van gedeelde circles
                        $sub->select('user_id')
                            ->from('circles')
                            ->whereIn('id', $sharedCircleIds);
                    });
                }
            });

            return CommentResource::collection(
                $base->paginate(20)->withQueryString()
            );
        }

        $paginated = $base->paginate(20)->withQueryString();

        // Bulk-lookup: welke auteurs op deze pagina delen minstens één
        // gedeelde-circle met de viewer? Eigen comments zijn altijd zichtbaar.
        // Beide paden (member + owner) meenemen, idem als bij sharedCircleIds.
        $authorIds = $paginated->getCollection()->pluck('user_id')->unique();

        if ($sharedCircleIds->isEmpty()) {
            $visibleAuthorIds = collect([$userId]);
        } else {
            $memberIds = DB::table('circle_user')
                ->whereIn('user_id', $authorIds)
                ->whereIn('circle_id', $sharedCircleIds)
                ->distinct()
                ->pluck('user_id');

            $ownerIds = DB::table('circles')
                ->whereIn('user_id', $authorIds)
                ->whereIn('id', $sharedCircleIds)
                ->pluck('user_id');

            $visibleAuthorIds = $memberIds->concat($ownerIds)->unique()->push($userId);
        }

        $visibleSet = $visibleAuthorIds->flip();

        $paginated->getCollection()->each(function (Comment $comment) use ($visibleSet) {
            $comment->setAttribute('is_visible', $visibleSet->has($comment->user_id));
        });

        return CommentResource::collection($paginated);
    }

    #[OA\Post(
        path: '/api/posts/{post}/comments',
        summary: 'Add comment',
        description: 'Add a comment to a post.',
        tags: ['Comments'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['body'],
                properties: [
                    new OA\Property(property: 'body', type: 'string', maxLength: 1000, example: 'Great post!'),
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
        $this->authorize('view', $post);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        $comment->load('user:id,name,username,avatar');

        $commenterId = $request->user()->id;

        if ($commenterId !== $post->user_id) {
            $post->user->notify(new PostCommented($request->user(), $post, $comment));
        }

        // Notificeer de overige gespreksdeelnemers: iedereen die eerder op deze
        // post reageerde, behalve de reageerder zelf en de eigenaar (die hierboven
        // al de PostCommented-notificatie kreeg). Eén geïndexeerde subquery op
        // comments.post_id, gechunkt om bij veel deelnemers efficiënt te blijven.
        User::whereIn('id', function ($query) use ($post): void {
            $query->select('user_id')
                ->distinct()
                ->from('comments')
                ->where('post_id', $post->id);
        })
            ->whereNot('id', $commenterId)
            ->whereNot('id', $post->user_id)
            ->chunkById(200, function ($recipients) use ($request, $post, $comment): void {
                Notification::send($recipients, new CommentReplied($request->user(), $post, $comment));
            });

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
            new OA\Parameter(name: 'comment', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
