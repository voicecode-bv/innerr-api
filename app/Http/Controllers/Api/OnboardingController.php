<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class OnboardingController extends Controller
{
    #[OA\Post(
        path: '/api/onboarding/complete',
        summary: 'Mark onboarding as completed',
        description: 'Sets the `onboarded_at` timestamp on the authenticated user to the current time.',
        tags: ['Account'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Onboarding marked as completed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function __invoke(Request $request): Response
    {
        $request->user()->forceFill(['onboarded_at' => now()])->save();

        return response()->noContent();
    }
}
