<?php

namespace App\Http\Controllers\Api;

use App\Enums\TagType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTagRequest;
use App\Http\Requests\UpdateTagAvatarRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class TagController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/tags',
        summary: 'List tags',
        description: 'Return the authenticated user\'s tags. Sorted by `usage_count` descending so the most-used (favorite) tags come first. Optionally filter by `type` to retrieve only regular tags or only persons.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['tag', 'person'])),
        ],
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
        $request->validate([
            'type' => ['sometimes', new Enum(TagType::class)],
        ]);

        $tags = $request->user()->tags()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->limit(1000)
            ->get();

        return TagResource::collection($tags);
    }

    #[OA\Post(
        path: '/api/tags',
        summary: 'Create tag',
        description: 'Create a new tag (or person) for the authenticated user. Names must be unique per user per type.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'type', type: 'string', enum: ['tag', 'person'], default: 'tag', description: 'The kind of entry. Defaults to `tag`.'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 50, example: 'travel'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true, description: 'Optional. Only allowed when `type=person`. Must be on or before today and after 1900-01-01.'),
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
        description: 'Rename an existing tag. For persons, the `birthdate` may also be updated (or cleared by sending `null`). Requires ownership.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 50),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true, description: 'Only allowed when the tag is of type `person`. Send `null` to clear.'),
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

    #[OA\Post(
        path: '/api/tags/{tag}/avatar',
        summary: 'Upload tag avatar',
        description: 'Upload an avatar image for a person tag. Only allowed when the tag is of type `person`. Replaces any existing avatar.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['avatar'],
                    properties: [
                        new OA\Property(property: 'avatar', type: 'string', format: 'binary'),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Avatar uploaded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Tag'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Tag not found'),
            new OA\Response(response: 422, description: 'Validation error or tag is not a person'),
        ],
    )]
    public function updateAvatar(UpdateTagAvatarRequest $request, Tag $tag, MediaUploadService $media): TagResource
    {
        $this->authorize('update', $tag);

        if (! $tag->isPerson()) {
            throw ValidationException::withMessages([
                'avatar' => __('An avatar may only be uploaded for a person.'),
            ]);
        }

        $media->delete($tag->avatar);
        $media->delete($tag->avatar_thumbnail);

        $file = $request->file('avatar');

        $path = $media->store(
            $file,
            $request->user()->id,
            'tag-avatars',
            width: 500,
            height: 500,
            cover: true,
        );

        $thumbnailPath = $media->generateImageThumbnail($file, $request->user()->id, 'tag-avatars', size: 150);

        $tag->update([
            'avatar' => $path,
            'avatar_thumbnail' => $thumbnailPath,
        ]);

        return new TagResource($tag);
    }

    #[OA\Delete(
        path: '/api/tags/{tag}/avatar',
        summary: 'Delete tag avatar',
        description: 'Remove the avatar from a person tag. No-op if the tag has no avatar.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Avatar removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Tag'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Tag not found'),
        ],
    )]
    public function deleteAvatar(Tag $tag, MediaUploadService $media): TagResource
    {
        $this->authorize('update', $tag);

        if ($tag->avatar !== null || $tag->avatar_thumbnail !== null) {
            $media->delete($tag->avatar);
            $media->delete($tag->avatar_thumbnail);
            $tag->update(['avatar' => null, 'avatar_thumbnail' => null]);
        }

        return new TagResource($tag);
    }

    #[OA\Delete(
        path: '/api/tags/{tag}',
        summary: 'Delete tag',
        description: 'Delete a tag. Requires ownership. The tag is detached from all posts.',
        tags: ['Tags'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
