<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AccountStorageController extends Controller
{
    #[OA\Get(
        path: '/api/account/storage',
        summary: 'Get storage usage',
        description: 'Return the authenticated user\'s storage usage in bytes alongside the limit derived from their current plan (1 GB on free, 100 GB on plus, 1 TB on pro).',
        tags: ['Account'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Storage usage and plan limit',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'used_bytes', type: 'integer', format: 'int64', example: 12345),
                        new OA\Property(property: 'limit_bytes', type: 'integer', format: 'int64', nullable: true, example: 1073741824),
                        new OA\Property(property: 'plan', type: 'object', properties: [
                            new OA\Property(property: 'slug', type: 'string', example: 'free'),
                            new OA\Property(property: 'name', type: 'string', example: 'Free'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $plan = $user->currentPlan();

        return response()->json([
            'used_bytes' => (int) ($user->storage_used_bytes ?? 0),
            'limit_bytes' => $plan->maxStorageBytes(),
            'plan' => [
                'slug' => $plan->slug,
                'name' => $plan->name,
            ],
        ]);
    }
}
