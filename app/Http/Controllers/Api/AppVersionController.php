<?php

namespace App\Http\Controllers\Api;

use App\Enums\AppPlatform;
use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class AppVersionController extends Controller
{
    #[OA\Get(
        path: '/api/app-version',
        summary: 'Get app version info',
        description: 'Return the newest store version and the minimum supported version for the given platform, so clients can show an update prompt or a blocking update-required screen. All fields are null while no release is configured.',
        tags: ['Meta'],
        parameters: [
            new OA\Parameter(name: 'platform', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['ios', 'android'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Version info',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'latest_version', type: 'string', nullable: true),
                            new OA\Property(property: 'minimum_version', type: 'string', nullable: true),
                            new OA\Property(property: 'store_url', type: 'string', nullable: true),
                        ], type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', Rule::enum(AppPlatform::class)],
        ]);

        $release = AppRelease::query()
            ->where('platform', $validated['platform'])
            ->first();

        return response()->json([
            'data' => [
                'latest_version' => $release?->latest_version,
                'minimum_version' => $release?->minimum_version,
                'store_url' => $release?->store_url,
            ],
        ]);
    }
}
