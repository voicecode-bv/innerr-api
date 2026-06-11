<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PersonParentController extends Controller
{
    use AuthorizesRequests;

    #[OA\Post(
        path: '/api/persons/{person}/parents',
        summary: 'Add a parent to a child',
        description: 'Assign another user as parent (co-manager) of a child. Only existing parents (including the creator) may do this, and the new parent must share at least one circle with the child. Parents manage the child independent of circle roles: they can edit, attach to circles and assign other parents.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'username', type: 'string', description: 'One of username, email or user_id is required.'),
                new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Parent added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(Request $request, Person $person): JsonResponse
    {
        $this->authorize('manageParents', $person);

        $validated = $request->validate([
            'username' => ['required_without_all:email,user_id', 'nullable', 'string', 'exists:users,username'],
            'email' => ['required_without_all:username,user_id', 'nullable', 'email', 'max:255'],
            'user_id' => ['required_without_all:username,email', 'nullable', 'uuid', 'exists:users,id'],
        ]);

        [$field, $parent] = match (true) {
            isset($validated['user_id']) => ['user_id', User::find($validated['user_id'])],
            isset($validated['username']) => ['username', User::where('username', $validated['username'])->first()],
            default => ['email', User::where('email', $validated['email'])->first()],
        };

        if ($parent === null) {
            throw ValidationException::withMessages([
                $field => __('validation.exists', ['attribute' => $field]),
            ]);
        }

        // A parent must be able to see the child: owner or member of at least
        // one of the child's circles. This keeps random accounts out.
        $sharesCircle = $person->circles()
            ->where(function ($query) use ($parent) {
                $query->where('circles.user_id', $parent->id)
                    ->orWhereHas('members', fn ($q) => $q->where('users.id', $parent->id));
            })
            ->exists();

        if (! $sharesCircle) {
            throw ValidationException::withMessages([
                $field => __('This person must be a member of one of the child\'s circles.'),
            ]);
        }

        $person->parents()->syncWithoutDetaching([$parent->id]);
        $person->load(['circles:id', 'parents:users.id,name,username,avatar,avatar_thumbnail']);

        return (new PersonResource($person))->response();
    }

    #[OA\Delete(
        path: '/api/persons/{person}/parents/{user}',
        summary: 'Remove a parent from a child',
        description: 'Remove a co-parent. Only parents may do this. The creator keeps management rights even when removed from the parent list.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'person', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Parent removed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Person')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function destroy(Request $request, Person $person, User $user): JsonResponse
    {
        $this->authorize('manageParents', $person);

        $person->parents()->detach($user->id);
        $person->load(['circles:id', 'parents:users.id,name,username,avatar,avatar_thumbnail']);

        return (new PersonResource($person))->response();
    }
}
