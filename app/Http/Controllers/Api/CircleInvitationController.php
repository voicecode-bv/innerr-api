<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CircleInvitationResource;
use App\Models\CircleInvitation;
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

    #[OA\Post(
        path: '/api/circle-invitations/{circleInvitation}/accept',
        summary: 'Accept invitation',
        description: 'Accept a pending circle invitation. The user will be added to the circle.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleInvitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
    public function accept(Request $request, CircleInvitation $circleInvitation): JsonResponse
    {
        if ($circleInvitation->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($circleInvitation->status !== InvitationStatus::Pending) {
            abort(403);
        }

        $circleInvitation->update(['status' => InvitationStatus::Accepted]);

        $circleInvitation->circle->members()->syncWithoutDetaching([$circleInvitation->user_id]);

        return response()->json(['message' => 'Invitation accepted.']);
    }

    #[OA\Post(
        path: '/api/circle-invitations/{circleInvitation}/decline',
        summary: 'Decline invitation',
        description: 'Decline a pending circle invitation.',
        tags: ['Circle Invitations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleInvitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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

        return response()->json(['message' => 'Invitation declined.']);
    }
}
