<?php

namespace App\Http\Controllers\Api;

use App\Enums\CircleMemberRole;
use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleRequest;
use App\Http\Requests\UpdateCircleRequest;
use App\Http\Requests\UpdateCircleSettingsRequest;
use App\Http\Resources\CircleResource;
use App\Models\Circle;
use App\Models\Post;
use App\Models\User;
use App\Rules\MaxImageDimensions;
use App\Services\MediaUploadService;
use App\Services\MemberPersonSyncer;
use App\Support\MediaUrl;
use App\Support\UserStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class CircleController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/circles',
        summary: 'List circles',
        description: 'Return all circles the authenticated user owns or is a member of. Each circle includes an `is_owner` flag. When `not_member_username` is provided, circles where that user is already the owner or a member are excluded — useful for surfacing circles the authenticated user can still invite that person to. An unknown username has no effect on the result.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(
                name: 'not_member_username',
                in: 'query',
                required: false,
                description: 'Exclude circles where the user with this username is already the owner or a member.',
                schema: new OA\Schema(type: 'string', example: 'janedoe'),
            ),
            new OA\Parameter(
                name: 'sort',
                in: 'query',
                required: false,
                description: 'Column to sort by. Defaults to `name`.',
                schema: new OA\Schema(type: 'string', enum: ['name', 'updated_at', 'created_at'], example: 'name'),
            ),
            new OA\Parameter(
                name: 'direction',
                in: 'query',
                required: false,
                description: 'Sort direction. Defaults to `asc`.',
                schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], example: 'asc'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of circles',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Circle')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $validated = $request->validate([
            'not_member_username' => ['sometimes', 'string'],
            'sort' => ['sometimes', 'string', 'in:name,updated_at,created_at'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $sortColumn = $validated['sort'] ?? 'name';
        $sortDirection = $validated['direction'] ?? 'asc';

        $excludeUserId = null;
        if ($request->filled('not_member_username')) {
            $excludeUserId = User::query()
                ->where('username', $request->string('not_member_username'))
                ->value('id');
        }

        $circles = Circle::query()
            ->withViewerRole($userId)
            ->where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhereHas('members', fn ($q) => $q->whereKey($userId));
            })
            ->when($excludeUserId !== null, function ($query) use ($excludeUserId) {
                $query->where('user_id', '!=', $excludeUserId)
                    ->whereDoesntHave('members', fn ($q) => $q->whereKey($excludeUserId))
                    ->with(['invitations' => fn ($q) => $q
                        ->where('user_id', $excludeUserId)
                        ->where('status', InvitationStatus::Pending),
                    ]);
            })
            ->withCount('members')
            ->orderBy($sortColumn, $sortDirection)
            ->limit(500)
            ->get();

        return CircleResource::collection($circles);
    }

    #[OA\Post(
        path: '/api/circles',
        summary: 'Create circle',
        description: 'Create a new circle for the authenticated user.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Close Friends'),
                    new OA\Property(property: 'members_can_invite', type: 'boolean', example: false),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Circle created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCircleRequest $request, MemberPersonSyncer $memberPersons): JsonResponse
    {
        $circle = $request->user()->circles()->create($request->validated());

        $memberPersons->attach($circle, $request->user());

        $circle->loadCount('members');

        return (new CircleResource($circle))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/circles/{circle}',
        summary: 'Show circle',
        description: 'Return a single circle with its members (including the owner). Accessible to the owner and to circle members. When `members_can_view_members` is false and the viewer is not the owner, the `members` array only contains the owner and the authenticated viewer. Pending invitations are only included for the owner, or for members when `members_can_invite` and `members_can_view_members` are both true.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Circle details',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function show(Request $request, Circle $circle): CircleResource
    {
        $this->authorize('view', $circle);

        $authUserId = $request->user()->id;
        $isOwner = $authUserId === $circle->user_id;
        $isAdministrator = ! $isOwner
            && $circle->resolveViewerRole($authUserId) === CircleMemberRole::Administrator->value;
        $isOwnerOrAdmin = $isOwner || $isAdministrator;
        $canViewMembers = $isOwnerOrAdmin || $circle->members_can_view_members;

        $circle->load([
            'user:id,name,username,avatar',
            'members' => fn ($query) => $query
                ->select('users.id', 'users.name', 'users.username', 'users.avatar')
                ->when(! $canViewMembers, fn ($q) => $q->whereKey($authUserId)),
        ])->loadCount('members');

        if ($canViewMembers && ($isOwnerOrAdmin || $circle->members_can_invite)) {
            $circle->load(['invitations' => fn ($query) => $query->where('status', InvitationStatus::Pending)->with('user:id,username')]);
        }

        $circle->load(['ownershipTransfers' => fn ($query) => $query
            ->where('status', InvitationStatus::Pending)
            ->with(['fromUser:id,name,username,avatar', 'toUser:id,name,username,avatar']),
        ]);

        return new CircleResource($circle);
    }

    #[OA\Put(
        path: '/api/circles/{circle}',
        summary: 'Update circle',
        description: 'Update an existing circle. Available to the circle owner and to administrators.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Best Friends'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Circle updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateCircleRequest $request, Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        $circle->update($request->validated());

        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    #[OA\Put(
        path: '/api/circles/{circle}/settings',
        summary: 'Update circle settings',
        description: 'Update circle settings such as whether members can invite others, view the member list, or download media. Available to the circle owner and to administrators. At least one setting must be present in the request body, settings not included are left unchanged.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'members_can_invite', type: 'boolean', example: true),
                    new OA\Property(property: 'members_can_view_members', type: 'boolean', example: true),
                    new OA\Property(property: 'members_can_download', type: 'boolean', example: false),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Circle settings updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Circle'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function updateSettings(UpdateCircleSettingsRequest $request, Circle $circle): CircleResource
    {
        $this->authorize('update', $circle);

        $circle->update($request->validated());

        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}',
        summary: 'Delete circle',
        description: 'Delete a circle. Only available to the circle owner.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Circle deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function destroy(Circle $circle): JsonResponse
    {
        $this->authorize('delete', $circle);

        if ($circle->photo) {
            $disk = MediaUrl::disk();
            UserStorage::trackDelete($circle->photo, $disk);
            $disk->delete($circle->photo);
        }

        // Snapshot voor cleanup: posts die ná de cascade mogelijk geen
        // andere circle meer hebben en daarmee voor alle niet-owners
        // ontoegankelijk worden. Notificaties blijven anders hangen als
        // dead deeplinks.
        $affectedPostIds = $circle->posts()->pluck('posts.id')->all();

        $circle->delete();

        if ($affectedPostIds !== []) {
            Post::query()
                ->whereIn('id', $affectedPostIds)
                ->chunkById(200, function ($posts) {
                    foreach ($posts as $post) {
                        $post->pruneNotificationsForLostAccess();
                    }
                });
        }

        return response()->json(null, 204);
    }

    public function updatePhoto(Request $request, Circle $circle, MediaUploadService $media): CircleResource
    {
        $this->authorize('update', $circle);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,heic,heif', 'max:10240', new MaxImageDimensions(4096, 4096)],
        ]);

        $media->delete($circle->photo);

        $path = $media->store(
            $request->file('photo'),
            $request->user()->id,
            'circles',
            width: 500,
            height: 500,
            cover: true,
        );

        $circle->update(['photo' => $path]);
        $circle->loadCount('members');

        return new CircleResource($circle);
    }

    public function deletePhoto(Circle $circle, MediaUploadService $media): CircleResource
    {
        $this->authorize('update', $circle);

        if ($circle->photo) {
            $media->delete($circle->photo);
            $circle->update(['photo' => null]);
        }

        $circle->loadCount('members');

        return new CircleResource($circle);
    }
}
