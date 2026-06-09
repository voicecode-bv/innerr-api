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
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class FeedController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/feed',
        summary: 'Feed',
        description: 'Return a paginated feed of posts, newest first. Optionally filter by `person_ids[]` (posts tagged with at least one of these persons), `tag_ids[]` (posts labeled with at least one of these tags), `circle_ids[]` (posts shared in at least one of these circles) and/or a capture-date range via `date_from`/`date_to` (filtered on `taken_at`; posts without a capture date are excluded when a date filter is active). Filter values that aren\'t visible to the authenticated user are silently dropped. All filter categories combine with AND.',
        tags: ['Feed'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'person_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'tag_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'circle_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort posts by upload time (`created_at`, default) or capture time (`taken_at`, falling back to `created_at` when no EXIF capture date is present). Always newest first.', schema: new OA\Schema(type: 'string', enum: ['created_at', 'taken_at'], default: 'created_at')),
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
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['created_at', 'taken_at'])],
        ]);

        $user = $request->user();

        $query = Post::with([
            'user:id,name,username,avatar',
            'circles:id,name,photo',
            'persons:id,name,birthdate,avatar_thumbnail,user_id',
            'persons.user:id,username',
            'media',
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
        $this->applyCircleFilters($query, $request, $user);
        $this->applyDateFilters($query, $request);

        $sortByTakenAt = $request->string('sort')->toString() === 'taken_at';

        $posts = $query
            ->withExists([
                'likes as is_liked' => fn ($q) => $q->where('user_id', $user->id),
                'circles as is_downloadable_via_circles' => fn ($q) => $q->where('members_can_download', true),
            ])
            // Capture-time sort falls back to upload time for posts without EXIF,
            // mirroring how the client renders `taken_at ?? created_at`.
            ->when(
                $sortByTakenAt,
                fn (Builder $q) => $q->orderByRaw('COALESCE(taken_at, created_at) DESC'),
                fn (Builder $q) => $q->latest(),
            )
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
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'person_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'tag_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
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
            'persons.user:id,username',
            'media',
        ])
            ->whereHas('circles', fn ($q) => $q->whereKey($circle->id));

        $this->applyTagFilters($query, $request, $user);
        $this->applyPersonFilters($query, $request, $user);

        $posts = $query
            ->withExists([
                'likes as is_liked' => fn ($q) => $q->where('user_id', $user->id),
                'circles as is_downloadable_via_circles' => fn ($q) => $q->where('members_can_download', true),
            ])
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
     * Restrict the feed to posts shared in at least one of the given circles.
     * Circles the authenticated user can't access (not owner, not member) are
     * silently dropped, mirroring the person/tag filters.
     *
     * @param  Builder<Post>  $query
     */
    private function applyCircleFilters(Builder $query, Request $request, User $user): void
    {
        if (! $request->has('circle_ids')) {
            return;
        }

        $requestedIds = $this->normalizeIds($request->input('circle_ids'));

        $visibleIds = $requestedIds === []
            ? []
            : Circle::whereIn('id', $requestedIds)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
                })
                ->pluck('id')
                ->all();

        $query->whereHas('circles', fn ($q) => $q->whereIn('circles.id', $visibleIds));
    }

    /**
     * Restrict the feed to posts whose capture date (taken_at) falls within the
     * given range. Posts without a taken_at fall outside any bound and are
     * therefore excluded when a date filter is active.
     *
     * @param  Builder<Post>  $query
     */
    private function applyDateFilters(Builder $query, Request $request): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate('taken_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('taken_at', '<=', $request->date('date_to'));
        }
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeIds($value): array
    {
        return collect((array) $value)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
