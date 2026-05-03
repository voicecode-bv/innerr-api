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
use App\Services\MemberPersonSyncer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class CircleMemberController extends Controller
{
    use AuthorizesRequests;

    #[OA\Post(
        path: '/api/circles/{circle}/members',
        summary: 'Invite member',
        description: 'Invite a user to a circle by username or email. Available to the circle owner, and to members when the circle has `members_can_invite` enabled. When a non-owner member sends the invitation, the circle owner receives a notification. If inviting by email, an email notification is always sent to the invitee.',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
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
            ->notify((new CircleInvitationNotification($invitation))
                ->locale($request->user()->preferredLocale() ?? app()->getLocale()));

        return response()->json(['message' => 'Invitation sent.'], 201);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}/members/{user}',
        summary: 'Remove member',
        description: 'Remove a user from a circle. Requires circle ownership. The owner cannot be removed from their own circle.',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Member removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle or user not found'),
        ],
    )]
    public function destroy(Circle $circle, User $user, MemberPersonSyncer $memberPersons): JsonResponse
    {
        $this->authorize('update', $circle);

        if ($user->id === $circle->user_id) {
            abort(403, 'The owner cannot be removed from the circle.');
        }

        $circle->members()->detach($user->id);
        $memberPersons->detach($circle, $user);

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/circles/{circle}/leave',
        summary: 'Leave circle',
        description: 'Leave a circle as the authenticated user. The circle owner cannot leave their own circle and must transfer ownership or delete the circle instead. Posts the leaving user shared in this circle are detached from it (they remain on the user\'s profile and in any other circles they were shared to).',
        tags: ['Circle Members'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Left circle'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Circle not found'),
        ],
    )]
    public function leave(Request $request, Circle $circle, MemberPersonSyncer $memberPersons): JsonResponse
    {
        $user = $request->user();

        if ($user->id === $circle->user_id) {
            abort(403, 'The owner cannot leave their own circle.');
        }

        if (! $circle->members()->whereKey($user->id)->exists()) {
            abort(403);
        }

        $circle->members()->detach($user->id);
        $memberPersons->detach($circle, $user);

        DB::table('circle_post')
            ->where('circle_id', $circle->id)
            ->whereIn('post_id', $user->posts()->select('id'))
            ->delete();

        $defaultCircleIds = $user->default_circle_ids ?? [];

        if (in_array($circle->id, $defaultCircleIds, true)) {
            $user->update([
                'default_circle_ids' => array_values(array_filter(
                    $defaultCircleIds,
                    fn (string $id): bool => $id !== $circle->id,
                )),
            ]);
        }

        return response()->json(null, 204);
    }
}
