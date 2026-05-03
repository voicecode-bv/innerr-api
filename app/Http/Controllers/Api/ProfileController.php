<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfilePostResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Models\Person;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Support\MediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class ProfileController extends Controller
{
    #[OA\Get(
        path: '/api/profiles/{username}',
        summary: 'Show profile',
        description: 'Return a user\'s public profile with post count.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Profile'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'User not found'),
        ],
    )]
    public function show(Request $request, User $user): ProfileResource
    {
        $authId = $request->user()->id;

        $user->loadCount(['posts' => function ($query) use ($authId, $user) {
            if ($authId === $user->id) {
                return;
            }

            $query->whereHas('circles', function ($circleQuery) use ($authId) {
                $circleQuery->where('circles.user_id', $authId)
                    ->orWhereHas('members', fn ($q) => $q->whereKey($authId));
            });
        }]);

        return new ProfileResource($user);
    }

    #[OA\Get(
        path: '/api/profiles/{username}/posts',
        summary: 'List profile posts',
        description: 'Return a paginated list of posts by the given user.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'username', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of user posts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProfilePost')),
                        new OA\Property(property: 'links', type: 'object'),
                        new OA\Property(property: 'meta', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'User not found'),
        ],
    )]
    public function posts(Request $request, User $user): AnonymousResourceCollection
    {
        $authId = $request->user()->id;

        $query = $user->posts()
            ->withExists(['likes as is_liked' => fn ($q) => $q->where('user_id', $authId)])
            ->latest();

        if ($authId !== $user->id) {
            $query->whereHas('circles', function ($circleQuery) use ($authId) {
                $circleQuery->where('circles.user_id', $authId)
                    ->orWhereHas('members', fn ($q) => $q->whereKey($authId));
            });
        }

        return ProfilePostResource::collection($query->paginate(21));
    }

    #[OA\Put(
        path: '/api/profile',
        summary: 'Update profile',
        description: 'Update the authenticated user\'s profile details.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'bio', type: 'string', nullable: true, example: 'Hello world'),
                    new OA\Property(property: 'locale', type: 'string', example: 'en'),
                    new OA\Property(property: 'donation_percentage', type: 'integer', minimum: 0, maximum: 100, example: 5),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    #[OA\Patch(
        path: '/api/profile',
        summary: 'Partially update profile',
        description: 'Partially update the authenticated user\'s profile details. Only the provided fields are updated.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'bio', type: 'string', nullable: true, example: 'Hello world'),
                    new OA\Property(property: 'locale', type: 'string', example: 'en'),
                    new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true, example: '1990-01-01'),
                    new OA\Property(property: 'donation_percentage', type: 'integer', minimum: 0, maximum: 100, example: 5),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->validated();

        $hasBirthdate = array_key_exists('birthdate', $data);
        $birthdate = $data['birthdate'] ?? null;
        unset($data['birthdate']);

        $user->update($data);

        if ($hasBirthdate) {
            $person = Person::firstOrCreate(
                ['user_id' => $user->id],
                ['created_by_user_id' => $user->id, 'name' => $user->name],
            );
            $person->update(['birthdate' => $birthdate]);
        }

        return new UserResource($user->fresh());
    }

    #[OA\Get(
        path: '/api/profile',
        summary: 'Show editable profile',
        description: 'Return the authenticated user\'s editable profile fields, including the birthdate stored on the linked person record.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Editable profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'name', type: 'string'),
                            new OA\Property(property: 'username', type: 'string'),
                            new OA\Property(property: 'avatar', type: 'string', nullable: true),
                            new OA\Property(property: 'bio', type: 'string', nullable: true),
                            new OA\Property(property: 'birthdate', type: 'string', format: 'date', nullable: true),
                            new OA\Property(property: 'donation_percentage', type: 'integer', minimum: 0, maximum: 100),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function showSelf(Request $request): JsonResponse
    {
        $user = $request->user()->load('person:id,user_id,birthdate');

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar' => MediaUrl::sign($user->avatar),
                'bio' => $user->bio,
                'birthdate' => $user->person?->birthdate?->toDateString(),
                'donation_percentage' => $user->donation_percentage,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/profile/avatar',
        summary: 'Upload avatar',
        description: 'Upload a new avatar image for the authenticated user. Accepts JPG, PNG, GIF, and HEIC formats.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
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
                description: 'Avatar updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function updateAvatar(UpdateAvatarRequest $request, MediaUploadService $media): JsonResponse
    {
        $user = $request->user();

        $media->delete($user->avatar);
        $media->delete($user->avatar_thumbnail);

        $file = $request->file('avatar');

        $path = $media->store(
            $file,
            $user->id,
            'avatars',
            width: 500,
            height: 500,
            cover: true,
        );

        $thumbnailPath = $media->generateImageThumbnail($file, $user->id, 'avatars', size: 150);

        $user->update([
            'avatar' => $path,
            'avatar_thumbnail' => $thumbnailPath,
        ]);

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Delete(
        path: '/api/profile/avatar',
        summary: 'Delete avatar',
        description: 'Remove the authenticated user\'s avatar.',
        tags: ['Profiles'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Avatar removed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function deleteAvatar(MediaUploadService $media): JsonResponse
    {
        $user = request()->user();

        if ($user->avatar) {
            $media->delete($user->avatar);
            $media->delete($user->avatar_thumbnail);
            $user->update(['avatar' => null, 'avatar_thumbnail' => null]);
        }

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }
}
