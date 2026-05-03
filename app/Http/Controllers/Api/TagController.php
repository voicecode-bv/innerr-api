<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class TagController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/tags',
        summary: 'List tags',
        description: 'Return the authenticated user\'s personal tags. Sorted by `usage_count` descending so the most-used (favorite) tags come first.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of tags',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Tag')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $tags = $request->user()->tags()
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->limit(1000)
            ->get();

        return TagResource::collection($tags);
    }

    #[OA\Post(
        path: '/api/tags',
        summary: 'Create tag',
        description: 'Create a new tag for the authenticated user. Names must be unique per user.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 50, example: 'travel'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Tag created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Tag'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreTagRequest $request): JsonResponse
    {
        $tag = $request->user()->tags()->create($request->validated());

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/tags/{tag}',
        summary: 'Update tag',
        description: 'Rename an existing tag. Requires ownership.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 50),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tag updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Tag'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Tag not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $tag->update($request->validated());

        return new TagResource($tag);
    }

    #[OA\Delete(
        path: '/api/tags/{tag}',
        summary: 'Delete tag',
        description: 'Delete a tag. Requires ownership. The tag is detached from all posts.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Tag deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Tag not found'),
        ],
    )]
    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return response()->json(null, 204);
    }
}
