<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Circle;
use App\Models\Person;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class FeedController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/feed',
        summary: 'Feed',
        description: 'Return a paginated feed of posts, newest first. Optionally filter by `person_ids[]` (show only posts tagged with at least one of these persons) or `tag_ids[]` (show only posts labeled with at least one of these tags). Filter values that aren\'t visible to the authenticated user are silently dropped.',
        tags: ['Feed'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'person_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'tag_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of posts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Post')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Post::with([
            'user:id,name,username,avatar',
            'circles:id,name,photo',
            'persons:id,name,birthdate,avatar_thumbnail,user_id',
        ])
            ->where(function ($q) use ($user) {
                $q->where('posts.user_id', $user->id)
                    ->orWhereHas('circles', function ($cq) use ($user) {
                        $cq->where('circles.user_id', $user->id)
                            ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
                    });
            });

        $this->applyTagFilters($query, $request, $user);
        $this->applyPersonFilters($query, $request, $user);

        $posts = $query
            ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return PostResource::collection($posts);
    }

    #[OA\Get(
        path: '/api/circles/{circle}/feed',
        summary: 'Circle feed',
        description: 'Return a paginated feed of posts in a single circle, newest first. Restricted to circles the authenticated user owns or is a member of. Supports the same `person_ids[]` and `tag_ids[]` filters as the main feed.',
        tags: ['Feed'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'person_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'tag_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of posts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Post')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function circle(Request $request, Circle $circle): AnonymousResourceCollection
    {
        $this->authorize('view', $circle);

        $user = $request->user();

        $query = Post::with([
            'user:id,name,username,avatar',
            'circles:id,name,photo',
            'persons:id,name,birthdate,avatar_thumbnail,user_id',
        ])
            ->whereHas('circles', fn ($q) => $q->whereKey($circle->id));

        $this->applyTagFilters($query, $request, $user);
        $this->applyPersonFilters($query, $request, $user);

        $posts = $query
            ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $user->id)])
            ->latest()
            ->paginate(21)
            ->withQueryString();

        return PostResource::collection($posts);
    }

    /**
     * @param  Builder<Post>  $query
     */
    private function applyTagFilters(Builder $query, Request $request, User $user): void
    {
        if (! $request->has('tag_ids')) {
            return;
        }

        $requestedIds = $this->normalizeIds($request->input('tag_ids'));

        $visibleIds = $requestedIds === []
            ? []
            : Tag::whereIn('id', $requestedIds)
                ->where('user_id', $user->id)
                ->pluck('id')
                ->all();

        $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $visibleIds));
    }

    /**
     * @param  Builder<Post>  $query
     */
    private function applyPersonFilters(Builder $query, Request $request, User $user): void
    {
        if (! $request->has('person_ids')) {
            return;
        }

        $requestedIds = $this->normalizeIds($request->input('person_ids'));

        $visibleIds = $requestedIds === []
            ? []
            : Person::whereIn('id', $requestedIds)
                ->whereHas('circles', function ($q) use ($user) {
                    $q->where('circles.user_id', $user->id)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
                })
                ->pluck('id')
                ->all();

        $query->whereHas('persons', fn ($q) => $q->whereIn('people.id', $visibleIds));
    }

    /**
     * @param  mixed  $value
     * @return array<int, int>
     */
    private function normalizeIds($value): array
    {
        return collect((array) $value)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
