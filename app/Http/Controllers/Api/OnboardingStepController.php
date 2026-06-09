<?php

namespace App\Http\Controllers\Api;

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Http\Controllers\Controller;
use App\Models\OnboardingStep;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class OnboardingStepController extends Controller
{
    #[OA\Post(
        path: '/api/onboarding/steps',
        summary: 'Mark an onboarding step as completed',
        description: 'Records that the authenticated user completed the given onboarding step. Idempotent: re-posting the same step is a no-op and keeps the original `completed_at` timestamp.',
        tags: ['Account'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['step'],
                properties: [
                    new OA\Property(property: 'step', type: 'string', enum: ['intro', 'first_circle', 'add_children', 'invite_members', 'notifications']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Step recorded'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'step' => ['required', Rule::enum(OnboardingStepEnum::class)],
        ]);

        OnboardingStep::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'step' => $validated['step'],
            ],
            ['completed_at' => now()],
        );

        return response()->noContent();
    }
}
