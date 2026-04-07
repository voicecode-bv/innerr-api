<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleMemberRequest;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Notifications\CircleInvitationNotification;
use App\Notifications\CircleMemberInvitedByMemberNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class CircleMemberController extends Controller
{
    use AuthorizesRequests;

    #[OA\Post(
        path: '/api/circles/{circle}/members',
        summary: 'Invite member',
        description: 'Invite a user to a circle by username or email. If inviting by username and the user has previously accepted an invitation to this circle, they are added directly. If inviting by email, an email notification is always sent.',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
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
        $this->authorize('invite', $circle);

        $response = $request->has('email')
            ? $this->inviteByEmail($request, $circle)
            : $this->inviteByUsername($request, $circle);

        if ($request->user()->id !== $circle->user_id && $this->lastInvitation !== null) {
            $circle->user->notify(new CircleMemberInvitedByMemberNotification(
                $this->lastInvitation,
                $request->user()->name,
                $this->lastInvitation->user?->username ?? $this->lastInvitation->email,
            ));
        }

        return $response;
    }

    private ?CircleInvitation $lastInvitation = null;

    private function inviteByUsername(StoreCircleMemberRequest $request, Circle $circle): JsonResponse
    {
        $user = User::where('username', $request->validated('username'))->first();

        $this->lastInvitation = CircleInvitation::updateOrCreate(
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

    private function inviteByEmail(StoreCircleMemberRequest $request, Circle $circle): JsonResponse
    {
        $email = strtolower($request->validated('email'));
        $existingUser = User::where('email', $email)->first();

        $this->lastInvitation = $invitation = CircleInvitation::updateOrCreate(
            [
                'circle_id' => $circle->id,
                'email' => $email,
                'status' => InvitationStatus::Pending,
            ],
            [
                'user_id' => $existingUser?->id,
                'inviter_id' => $request->user()->id,
                'token' => Str::random(64),
            ],
        );

        Notification::route('mail', $email)
            ->notify(new CircleInvitationNotification($invitation));

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

        if ($user->id === $circle->user_id) {
            abort(403, 'The owner cannot be removed from the circle.');
        }

        $circle->members()->detach($user->id);

        return response()->json(null, 204);
    }
}
