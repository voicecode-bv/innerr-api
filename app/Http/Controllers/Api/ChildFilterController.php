<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ChildFilterController extends Controller
{
    #[OA\Get(
        path: '/api/child-filter',
        summary: 'Get child filter',
        description: 'Return the authenticated user\'s child filter: the person IDs the home feed is scoped to. An empty array means "all children".',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Selected person IDs',
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
            'data' => $request->user()->child_filter_ids ?? [],
        ]);
    }

    #[OA\Put(
        path: '/api/child-filter',
        summary: 'Update child filter',
        description: 'Update the authenticated user\'s child filter: the person IDs the home feed is scoped to. Pass an empty array for "all children". IDs of persons the user cannot see are silently dropped.',
        tags: ['Persons'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['person_ids'],
                properties: [
                    new OA\Property(property: 'person_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['019deefe-f707-715c-a486-9a73e8f533a7']),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Child filter updated',
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
            'person_ids' => ['present', 'array', 'max:1000'],
            'person_ids.*' => ['uuid', 'distinct'],
        ]);

        // Mirrors the default-circles endpoint: ids outside the user's view
        // are silently dropped rather than rejected, so a stale id (removed
        // child, left circle) can never make the filter unsaveable.
        $visibleIds = Person::query()
            ->whereIn('id', $validated['person_ids'])
            ->visibleTo($request->user())
            ->pluck('id');

        $filteredIds = collect($validated['person_ids'])
            ->intersect($visibleIds)
            ->values()
            ->all();

        $request->user()->update([
            'child_filter_ids' => $filteredIds,
        ]);

        return response()->json([
            'data' => $request->user()->child_filter_ids,
        ]);
    }
}
