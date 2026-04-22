<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleOwnershipTransferRequest;
use App\Http\Resources\CircleOwnershipTransferResource;
use App\Models\Circle;
use App\Models\CircleOwnershipTransfer;
use App\Notifications\CircleOwnershipTransferAcceptedNotification;
use App\Notifications\CircleOwnershipTransferRequestedNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CircleOwnershipTransferController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/circle-ownership-transfers',
        summary: 'List pending ownership transfers',
        description: 'Return all pending ownership transfers offered to the authenticated user.',
        tags: ['Circle Ownership Transfers'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of pending ownership transfers',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/CircleOwnershipTransfer'),
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $transfers = CircleOwnershipTransfer::where('to_user_id', $request->user()->id)
            ->where('status', InvitationStatus::Pending)
            ->with(['circle:id,name', 'fromUser:id,name,username,avatar'])
            ->latest()
            ->get();

        return CircleOwnershipTransferResource::collection($transfers);
    }

    #[OA\Post(
        path: '/api/circles/{circle}/ownership-transfer',
        summary: 'Request ownership transfer',
        description: 'Request to transfer ownership of a circle to another member. Only the current owner may initiate. The target user must already be a member. Only one pending transfer can exist per circle.',
        tags: ['Circle Ownership Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 42),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Ownership transfer requested',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Ownership transfer requested.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 409, description: 'A pending transfer already exists for this circle'),
            new OA\Response(response: 422, description: 'Validation error or target is not a member'),
        ],
    )]
    public function store(StoreCircleOwnershipTransferRequest $request, Circle $circle): JsonResponse
    {
        $this->authorize('transferOwnership', $circle);

        $targetUserId = (int) $request->validated('user_id');

        if ($targetUserId === $circle->user_id) {
            abort(422, 'The target user is already the owner.');
        }

        if (! $circle->members()->whereKey($targetUserId)->exists()) {
            abort(422, 'The target user is not a member of this circle.');
        }

        if (CircleOwnershipTransfer::where('circle_id', $circle->id)->where('status', InvitationStatus::Pending)->exists()) {
            abort(409, 'A pending transfer already exists for this circle.');
        }

        $transfer = CircleOwnershipTransfer::create([
            'circle_id' => $circle->id,
            'from_user_id' => $circle->user_id,
            'to_user_id' => $targetUserId,
            'status' => InvitationStatus::Pending,
        ]);

        $transfer->toUser->notify(new CircleOwnershipTransferRequestedNotification($transfer));

        return response()->json(['message' => 'Ownership transfer requested.'], 201);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}/ownership-transfer/{circleOwnershipTransfer}',
        summary: 'Cancel ownership transfer',
        description: 'Cancel a pending ownership transfer. Requires circle ownership.',
        tags: ['Circle Ownership Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'circleOwnershipTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Transfer cancelled'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Transfer not found'),
        ],
    )]
    public function destroy(Request $request, Circle $circle, CircleOwnershipTransfer $circleOwnershipTransfer): JsonResponse
    {
        $this->authorize('transferOwnership', $circle);

        if ($circleOwnershipTransfer->circle_id !== $circle->id) {
            abort(404);
        }

        if ($circleOwnershipTransfer->status !== InvitationStatus::Pending) {
            abort(403);
        }

        $circleOwnershipTransfer->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/circle-ownership-transfers/{circleOwnershipTransfer}/accept',
        summary: 'Accept ownership transfer',
        description: 'Accept a pending ownership transfer. The accepting user becomes the new owner; the previous owner becomes a regular member.',
        tags: ['Circle Ownership Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleOwnershipTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transfer accepted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Ownership transfer accepted.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function accept(Request $request, CircleOwnershipTransfer $circleOwnershipTransfer): JsonResponse
    {
        if ($circleOwnershipTransfer->to_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($circleOwnershipTransfer->status !== InvitationStatus::Pending) {
            abort(403);
        }

        DB::transaction(function () use ($circleOwnershipTransfer) {
            $circle = $circleOwnershipTransfer->circle;
            $previousOwnerId = $circle->user_id;
            $newOwnerId = $circleOwnershipTransfer->to_user_id;

            $circle->update(['user_id' => $newOwnerId]);
            $circle->members()->detach($newOwnerId);
            $circle->members()->syncWithoutDetaching([$previousOwnerId]);

            CircleOwnershipTransfer::where('circle_id', $circle->id)
                ->where('id', '!=', $circleOwnershipTransfer->id)
                ->whereNot('status', InvitationStatus::Pending)
                ->delete();

            $circleOwnershipTransfer->update(['status' => InvitationStatus::Accepted]);
        });

        $circleOwnershipTransfer->fromUser->notify(
            new CircleOwnershipTransferAcceptedNotification($circleOwnershipTransfer)
        );

        return response()->json(['message' => 'Ownership transfer accepted.']);
    }

    #[OA\Post(
        path: '/api/circle-ownership-transfers/{circleOwnershipTransfer}/decline',
        summary: 'Decline ownership transfer',
        description: 'Decline a pending ownership transfer.',
        tags: ['Circle Ownership Transfers'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circleOwnershipTransfer', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transfer declined',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Ownership transfer declined.'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function decline(Request $request, CircleOwnershipTransfer $circleOwnershipTransfer): JsonResponse
    {
        if ($circleOwnershipTransfer->to_user_id !== $request->user()->id) {
            abort(403);
        }

        if ($circleOwnershipTransfer->status !== InvitationStatus::Pending) {
            abort(403);
        }

        $circleOwnershipTransfer->update(['status' => InvitationStatus::Declined]);

        return response()->json(['message' => 'Ownership transfer declined.']);
    }
}
