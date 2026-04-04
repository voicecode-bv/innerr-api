<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleMemberRequest;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CircleMemberController extends Controller
{
    use AuthorizesRequests;

    #[OA\Post(
        path: '/api/circles/{circle}/members',
        summary: 'Invite member',
        description: 'Invite a user to a circle by username. If the user has previously accepted an invitation to this circle, they are added directly. Otherwise, an invitation is sent.',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['username'],
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Member added or invitation sent',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invitation sent.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCircleMemberRequest $request, Circle $circle): JsonResponse
    {
        $this->authorize('update', $circle);

        $user = User::where('username', $request->validated('username'))->first();

        $hasAcceptedBefore = CircleInvitation::where('circle_id', $circle->id)
            ->where('user_id', $user->id)
            ->where('status', InvitationStatus::Accepted)
            ->exists();

        if ($hasAcceptedBefore) {
            $circle->members()->syncWithoutDetaching([$user->id]);

            return response()->json(['message' => 'Member added.'], 201);
        }

        CircleInvitation::updateOrCreate(
            [
                'circle_id' => $circle->id,
                'user_id' => $user->id,
                'status' => InvitationStatus::Pending,
            ],
            [
                'inviter_id' => $request->user()->id,
            ],
        );

        return response()->json(['message' => 'Invitation sent.'], 201);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}/members/{user}',
        summary: 'Remove member',
        description: 'Remove a user from a circle. Requires circle ownership.',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Member removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle or user not found'),
        ],
    )]
    public function destroy(Circle $circle, User $user): JsonResponse
    {
        $this->authorize('update', $circle);

        $circle->members()->detach($user->id);

        return response()->json(null, 204);
    }
}
