<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportRequest;
use App\Mail\SupportRequestMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use OpenApi\Attributes as OA;

class SupportController extends Controller
{
    #[OA\Post(
        path: '/api/support',
        summary: 'Submit a support request',
        description: 'Emails an in-app support message (with app version and platform) to the support inbox.',
        tags: ['Support'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', maxLength: 5000),
                    new OA\Property(property: 'app_version', type: 'string', nullable: true, maxLength: 50),
                    new OA\Property(property: 'platform', type: 'string', nullable: true, enum: ['ios', 'android', 'web']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Support request received'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ],
    )]
    public function __invoke(StoreSupportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Mail::to(config('mail.support_address'))->send(new SupportRequestMail(
            supportMessage: $validated['message'],
            appVersion: $validated['app_version'] ?? null,
            platform: $validated['platform'] ?? null,
            sender: $request->user(),
        ));

        return response()->json(['message' => 'Support request received.'], 201);
    }
}
