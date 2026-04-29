<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWaitingListEntryRequest;
use App\Models\WaitingListEntry;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class WaitingListEntryController extends Controller
{
    public function store(StoreWaitingListEntryRequest $request): JsonResponse
    {
        WaitingListEntry::create($request->validated());

        return response()->json(['message' => 'Successfully joined the waiting list.'], 201);
    }

    #[OA\Get(
        path: '/api/waiting-list',
        summary: 'Waiting list signup count',
        description: 'Return the total number of waiting list signups. Public endpoint.',
        tags: ['Waiting List'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Waiting list count',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'count', type: 'integer', example: 1234),
                    ],
                ),
            ),
        ],
    )]
    public function count(): JsonResponse
    {
        return response()->json([
            'count' => WaitingListEntry::count(),
        ]);
    }
}
