<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ServiceKeyController extends Controller
{
    #[OA\Get(
        path: '/api/service-keys',
        summary: 'Get external service keys',
        description: 'Return public API keys for external services (Mapbox, etc.) for use by the client.',
        tags: ['Service Keys'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Public API keys for external services',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'mapbox', type: 'object', properties: [
                            new OA\Property(property: 'public_token', type: 'string', nullable: true),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'mapbox' => [
                'public_token' => config('services.mapbox.public_token'),
            ],
        ]);
    }
}
