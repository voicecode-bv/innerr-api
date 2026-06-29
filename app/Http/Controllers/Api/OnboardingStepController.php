<?php

namespace App\Http\Controllers\Api;

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Enums\OnboardingStepOutcome;
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
        summary: 'Record progress on an onboarding step',
        description: 'Records how the authenticated user progressed through the given onboarding step. `outcome` defaults to `completed` for older clients that only send `step`. Idempotent and monotonic: an outcome never downgrades from a terminal state (`completed`/`skipped`) back to `reached`, and the first terminal outcome keeps its `completed_at` timestamp.',
        tags: ['Account'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['step'],
                properties: [
                    new OA\Property(property: 'step', type: 'string', enum: ['intro', 'first_circle', 'add_children', 'first_moment', 'invite_members', 'notifications']),
                    new OA\Property(property: 'outcome', type: 'string', enum: ['reached', 'completed', 'skipped'], default: 'completed'),
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
            // Older app builds only send `step`; default to the historical
            // meaning of a tracked step, which was always an advance.
            'outcome' => ['sometimes', Rule::enum(OnboardingStepOutcome::class)],
        ]);

        $outcome = isset($validated['outcome'])
            ? OnboardingStepOutcome::from($validated['outcome'])
            : OnboardingStepOutcome::Completed;

        $step = OnboardingStep::firstOrNew([
            'user_id' => $request->user()->id,
            'step' => $validated['step'],
        ]);

        // Never let a later event downgrade a step the user already finished
        // (e.g. a stray 'reached' ping after a terminal outcome). A fresh row
        // takes the incoming outcome as-is.
        if (! $step->exists || ! $step->outcome->isTerminal()) {
            $step->outcome = $outcome;
            $step->completed_at = $outcome->isTerminal()
                ? ($step->completed_at ?? now())
                : null;
            $step->save();
        }

        return response()->noContent();
    }
}
