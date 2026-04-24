<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class FeedController extends Controller
{
    #[OA\Get(
        path: '/api/feed',
        summary: 'Feed',
        description: 'Return a paginated feed of posts, newest first.',
        tags: ['Feed'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
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

        $posts = Post::with([
            'user:id,name,username,avatar',
            'circles:id,name,photo',
        ])
            ->where(function ($query) use ($user) {
                $query->where('posts.user_id', $user->id)
                    ->orWhereHas('circles', function ($q) use ($user) {
                        $q->where('circles.user_id', $user->id)
                            ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
                    });
            })
            ->withExists(['likes as is_liked' => fn ($query) => $query->where('user_id', $user->id)])
            ->latest()
            ->paginate(10);

        return PostResource::collection($posts);
    }
}
