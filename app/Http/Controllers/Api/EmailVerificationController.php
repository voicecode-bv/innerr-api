<?php

namespace App\Http\Controllers\Api;

use App\Enums\EmailVerificationResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class EmailVerificationController extends Controller
{
    public function __construct(
        private EmailVerificationService $verification,
    ) {}

    #[OA\Post(
        path: '/api/auth/email/verify',
        summary: 'Verify email with code',
        description: 'Confirms the authenticated user\'s email address using the 6-digit code that was emailed to them.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: '123456'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Email verified',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Invalid or expired code'),
        ],
    )]
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        $result = $this->verification->verify($user, $validated['code']);

        return match ($result) {
            EmailVerificationResult::Verified,
            EmailVerificationResult::AlreadyVerified => response()->json([
                'user' => new UserResource($user->fresh()),
            ]),
            EmailVerificationResult::InvalidCode => throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect.'],
            ]),
            EmailVerificationResult::NoActiveCode => throw ValidationException::withMessages([
                'code' => ['This verification code has expired. Please request a new one.'],
            ]),
        };
    }

    #[OA\Post(
        path: '/api/auth/email/resend',
        summary: 'Resend verification code',
        description: 'Sends a fresh verification code to the authenticated user. Subject to a cooldown between requests.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Code sent (or email already verified)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 429, description: 'Resend cooldown not elapsed'),
        ],
    )]
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return response()->json([
                'user' => new UserResource($user),
            ]);
        }

        $wait = $this->verification->secondsUntilResendAllowed($user);

        if ($wait > 0) {
            return response()->json([
                'message' => 'Please wait before requesting another code.',
                'retry_after' => $wait,
            ], 429);
        }

        $this->verification->send($user);

        return response()->json([
            'message' => 'A new verification code has been sent.',
        ]);
    }
}
