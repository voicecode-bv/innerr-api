<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CircleInvitationResource;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Notifications\CircleInvitationAcceptedNotification;
use App\Services\MemberPersonSyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class CircleInvitationController extends Controller
{
    #[OA\Get(
        path: '/api/circle-invitations',
        summary: 'List pending invitations',
        description: 'Return all pending circle invitations for the authenticated user.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of pending invitations',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CircleInvitation'),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $invitations = CircleInvitation::where('user_id', $request->user()->id)
            ->where('status', InvitationStatus::Pending)
            ->with(['circle:id,name', 'inviter:id,name,username,avatar'])
            ->latest()
            ->get();

        return CircleInvitationResource::collection($invitations);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}/invitations/{circleInvitation}',
        summary: 'Cancel invitation',
        description: 'Cancel a pending circle invitation. Available to the circle owner, to circle administrators, and to the user who sent the invitation.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'circleInvitation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Invitation cancelled'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Invitation not found'),
        ],
    )]
    public function destroy(Request $request, Circle $circle, CircleInvitation $circleInvitation): JsonResponse
    {
        if ($circleInvitation->circle_id !== $circle->id) {
            abort(404);
        }

        $user = $request->user();

        if (! $circle->isOwnerOrAdministrator($user) && $circleInvitation->inviter_id !== $user->id) {
            abort(403);
        }

        $circleInvitation->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/circle-invitations/{circleInvitation}/accept',
        summary: 'Accept invitation',
        description: 'Accept a pending circle invitation. The user will be added to the circle.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleInvitation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invitation accepted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invitation accepted.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Invitation not found'),
        ],
    )]
    public function accept(Request $request, CircleInvitation $circleInvitation, MemberPersonSyncer $memberPersons): JsonResponse
    {
        if ($circleInvitation->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($circleInvitation->status !== InvitationStatus::Pending) {
            abort(403);
        }

        CircleInvitation::where('circle_id', $circleInvitation->circle_id)
            ->where('user_id', $circleInvitation->user_id)
            ->where('id', '!=', $circleInvitation->id)
            ->whereNot('status', InvitationStatus::Pending)
            ->delete();

        $circleInvitation->update(['status' => InvitationStatus::Accepted]);

        $circleInvitation->circle->members()->syncWithoutDetaching([$circleInvitation->user_id]);

        $memberPersons->attach($circleInvitation->circle, $request->user());

        $this->markReceivedNotificationsAsRead($request->user(), $circleInvitation);

        $circleInvitation->inviter->notify(
            new CircleInvitationAcceptedNotification($circleInvitation, $request->user()->name)
        );

        return response()->json(['message' => 'Invitation accepted.']);
    }

    #[OA\Post(
        path: '/api/circle-invitations/{circleInvitation}/decline',
        summary: 'Decline invitation',
        description: 'Decline a pending circle invitation.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleInvitation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invitation declined',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invitation declined.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Invitation not found'),
        ],
    )]
    public function decline(Request $request, CircleInvitation $circleInvitation): JsonResponse
    {
        if ($circleInvitation->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($circleInvitation->status !== InvitationStatus::Pending) {
            abort(403);
        }

        $circleInvitation->update(['status' => InvitationStatus::Declined]);

        $this->markReceivedNotificationsAsRead($request->user(), $circleInvitation);

        return response()->json(['message' => 'Invitation declined.']);
    }

    private function markReceivedNotificationsAsRead(User $user, CircleInvitation $invitation): void
    {
        $user->unreadNotifications()
            ->where('type', 'circle-invitation-received')
            ->whereRaw("(data::jsonb->>'invitation_id') = ?", [$invitation->id])
            ->update(['read_at' => now()]);
    }
}
