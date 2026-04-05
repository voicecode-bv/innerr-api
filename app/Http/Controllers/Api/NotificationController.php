<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    #[OA\Get(
        path: '/api/notifications',
        summary: 'List notifications',
        description: 'Get paginated list of notifications for the authenticated user.',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notifications list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return NotificationResource::collection($notifications);
    }

    #[OA\Post(
        path: '/api/notifications/read',
        summary: 'Mark notifications as read',
        description: 'Mark all or specific notifications as read.',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string'), description: 'Specific notification IDs to mark as read. Omit to mark all as read.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Notifications marked as read'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function markAsRead(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['required', 'uuid'],
        ]);

        $query = $request->user()->unreadNotifications();

        if ($request->has('ids')) {
            $query->whereIn('id', $request->input('ids'));
        }

        $query->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }
}
