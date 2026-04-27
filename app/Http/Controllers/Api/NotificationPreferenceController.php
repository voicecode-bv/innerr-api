<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateNotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class NotificationPreferenceController extends Controller
{
    #[OA\Get(
        path: '/api/notification-preferences',
        summary: 'Get notification preferences',
        description: 'Return the authenticated user\'s push notification preferences.',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notification preferences',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'post_liked', type: 'boolean'),
                            new OA\Property(property: 'post_commented', type: 'boolean'),
                            new OA\Property(property: 'comment_liked', type: 'boolean'),
                            new OA\Property(property: 'comment_replied', type: 'boolean'),
                            new OA\Property(property: 'new_circle_post', type: 'boolean'),
                            new OA\Property(property: 'circle_invitation_accepted', type: 'boolean'),
                        ]),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $preferences = $request->user()->notification_preferences ?? NotificationPreference::defaults();

        return response()->json(['data' => $preferences]);
    }

    #[OA\Put(
        path: '/api/notification-preferences',
        summary: 'Update notification preferences',
        description: 'Update the authenticated user\'s push notification preferences.',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Send any subset of preference keys with boolean values. Unknown keys are stored as-is — only the provided keys are updated, the rest keep their current value.',
            content: new OA\JsonContent(
                additionalProperties: new OA\AdditionalProperties(type: 'boolean'),
                example: [
                    'post_liked' => true,
                    'comment_replied' => false,
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preferences updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        // Merge over de bestaande JSON: ontbrekende sleutels behouden hun
        // huidige waarde (of fallen terug op defaults) en onbekende sleutels
        // worden automatisch aangemaakt en opgeslagen.
        $current = $user->notification_preferences ?? NotificationPreference::defaults();

        $incoming = collect($request->validated())
            ->map(fn ($value) => (bool) $value)
            ->all();

        $user->update([
            'notification_preferences' => array_merge($current, $incoming),
        ]);

        return response()->json(['data' => $user->fresh()->notification_preferences]);
    }
}
