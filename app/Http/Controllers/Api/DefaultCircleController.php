<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DefaultCircleController extends Controller
{
    #[OA\Get(
        path: '/api/default-circles',
        summary: 'Get default circles',
        description: 'Return the authenticated user\'s default circle IDs for new posts.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Default circle IDs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->default_circle_ids ?? [],
        ]);
    }

    #[OA\Put(
        path: '/api/default-circles',
        summary: 'Update default circles',
        description: 'Update the authenticated user\'s default circle IDs for new posts.',
        tags: ['Circles'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['circle_ids'],
                properties: [
                    new OA\Property(property: 'circle_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['019deefe-f707-715c-a486-9a73e8f533a7']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Default circles updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'circle_ids' => ['present', 'array'],
            'circle_ids.*' => ['uuid', 'exists:circles,id'],
        ]);

        $userId = $request->user()->id;

        $userCircleIds = Circle::query()
            ->whereIn('id', $validated['circle_ids'])
            ->where(fn ($query) => $query
                ->where('user_id', $userId)
                ->orWhereHas('members', fn ($q) => $q->whereKey($userId)))
            ->pluck('id');

        $filteredIds = collect($validated['circle_ids'])
            ->intersect($userCircleIds)
            ->values()
            ->all();

        $request->user()->update([
            'default_circle_ids' => $filteredIds,
        ]);

        return response()->json([
            'data' => $request->user()->default_circle_ids,
        ]);
    }
}
