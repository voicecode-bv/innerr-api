<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonAvatarRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\PersonResource;
use App\Models\Circle;
use App\Models\Person;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PersonController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/persons',
        summary: 'List persons',
        description: 'Return persons visible to the authenticated user. By default, returns persons across every circle the user owns or is a member of, ordered by `usage_count` descending. Pass `?circle_id=` to scope the list to a single circle (the user must own or belong to that circle).',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of persons',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Person')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'circle_id' => ['sometimes', 'integer'],
        ]);

        $user = $request->user();

        $query = Person::with('circles:id')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->limit(1000);

        if ($request->filled('circle_id')) {
            $circle = Circle::findOrFail($request->integer('circle_id'));
            $this->authorize('view', $circle);

            $query->whereHas('circles', fn ($q) => $q->whereKey($circle->id));
        } else {
            $query->whereHas('circles', function ($q) use ($user) {
                $q->where('circles.user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id));
            });
        }

        return PersonResource::collection($query->get());
    }

    #[OA\Post(
        path: '/api/persons',
        summary: 'Create person',
        description: 'Create a new person and attach it to one or more circles. The authenticated user must own each circle, or be a member with `members_can_invite=true` on that circle. Optionally link the person to an existing user account via `user_id` — that user must be a member or owner of every selected circle.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'circle_ids'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 50),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'Optional. Link this person to an existing user account.'),
                    new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'integer')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Person created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StorePersonRequest $request): JsonResponse
    {
        $data = $request->validated();
        $circleIds = $data['circle_ids'];
        unset($data['circle_ids']);

        $person = Person::create([
            ...$data,
            'created_by_user_id' => $request->user()->id,
        ]);

        $person->circles()->sync($circleIds);
        $person->load('circles:id');

        return (new PersonResource($person))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/persons/{person}',
        summary: 'Update person',
        description: 'Update a person\'s name, birthdate, or linked user account. The authenticated user must be the creator, the owner of one of the linked circles, or a member with `members_can_invite=true` on one of those circles.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 50),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Person updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdatePersonRequest $request, Person $person): PersonResource
    {
        $this->authorize('update', $person);

        $person->update($request->validated());
        $person->load('circles:id');

        return new PersonResource($person);
    }

    #[OA\Post(
        path: '/api/persons/{person}/avatar',
        summary: 'Upload person avatar',
        description: 'Upload an avatar image for a person. Replaces any existing avatar.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(required: ['avatar'], properties: [new OA\Property(property: 'avatar', type: 'string', format: 'binary')]),
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Avatar uploaded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function updateAvatar(UpdatePersonAvatarRequest $request, Person $person, MediaUploadService $media): PersonResource
    {
        $this->authorize('update', $person);

        $media->delete($person->avatar);
        $media->delete($person->avatar_thumbnail);

        $file = $request->file('avatar');

        $path = $media->store(
            $file,
            $request->user()->id,
            'person-avatars',
            width: 500,
            height: 500,
            cover: true,
        );

        $thumbnailPath = $media->generateImageThumbnail($file, $request->user()->id, 'person-avatars', size: 150);

        $person->update([
            'avatar' => $path,
            'avatar_thumbnail' => $thumbnailPath,
        ]);

        $person->load('circles:id');

        return new PersonResource($person);
    }

    #[OA\Delete(
        path: '/api/persons/{person}/avatar',
        summary: 'Delete person avatar',
        description: 'Remove the avatar from a person. No-op if the person has no avatar.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Avatar removed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person not found'),
        ],
    )]
    public function deleteAvatar(Person $person, MediaUploadService $media): PersonResource
    {
        $this->authorize('update', $person);

        if ($person->avatar !== null || $person->avatar_thumbnail !== null) {
            $media->delete($person->avatar);
            $media->delete($person->avatar_thumbnail);
            $person->update(['avatar' => null, 'avatar_thumbnail' => null]);
        }

        $person->load('circles:id');

        return new PersonResource($person);
    }

    #[OA\Post(
        path: '/api/persons/{person}/circles/{circle}',
        summary: 'Attach person to a circle',
        description: 'Add an existing person to another circle. The authenticated user must own the target circle or be a member with `members_can_invite=true`.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Attached', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person or circle not found'),
            new OA\Response(response: 422, description: 'Person already in this circle, or linked user is not a member'),
        ],
    )]
    public function attachCircle(Person $person, Circle $circle): PersonResource
    {
        $this->authorize('attachToCircle', [$person, $circle]);

        if ($person->user_id !== null) {
            throw ValidationException::withMessages([
                'person' => __('Member persons are managed by circle membership and cannot be attached manually.'),
            ]);
        }

        $person->circles()->syncWithoutDetaching([$circle->id]);
        $person->load('circles:id');

        return new PersonResource($person);
    }

    #[OA\Delete(
        path: '/api/persons/{person}/circles/{circle}',
        summary: 'Detach person from a circle',
        description: 'Remove a person from a single circle. Posts that already tag this person are unaffected.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detached', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person or circle not found'),
        ],
    )]
    public function detachCircle(Person $person, Circle $circle): PersonResource
    {
        $this->authorize('detachFromCircle', [$person, $circle]);

        if ($person->user_id !== null) {
            throw ValidationException::withMessages([
                'person' => __('Member persons are managed by circle membership and cannot be detached manually. Remove the user from the circle instead.'),
            ]);
        }

        $person->circles()->detach($circle->id);
        $person->load('circles:id');

        return new PersonResource($person);
    }

    #[OA\Delete(
        path: '/api/persons/{person}',
        summary: 'Delete person',
        description: 'Delete a person completely. Detaches them from all circles and posts. Allowed for the creator, or any owner of a circle the person is in.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Person deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Person not found'),
        ],
    )]
    public function destroy(Person $person): JsonResponse
    {
        $this->authorize('delete', $person);

        if ($person->user_id !== null) {
            throw ValidationException::withMessages([
                'person' => __('Member persons cannot be deleted. Remove the user from the circle instead.'),
            ]);
        }

        $person->delete();

        return response()->json(null, 204);
    }
}
