<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class PasswordResetController extends Controller
{
    #[OA\Post(
        path: '/api/auth/forgot-password',
        summary: 'Request password reset link',
        description: 'Sends a password reset link to the given email address. Returns 200 even if the email is unknown to avoid user enumeration.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reset link sent (or silently ignored)'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => __('passwords.sent'),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/reset-password',
        summary: 'Reset password',
        description: 'Resets the user password using the token from the email link.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'token', type: 'string'),
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8),
                    new OA\Property(property: 'password_confirmation', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password reset'),
            new OA\Response(response: 422, description: 'Invalid token or validation error'),
        ],
    )]
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'string', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Een wachtwoordreset is vaak een respons op een vermoedelijke
                // compromittering. Trek alle bestaande sessies, API-tokens en
                // push-bestemmingen in zodat een gestolen token niet langer
                // werkt — anders blijft de aanvaller ingelogd ondanks de reset.
                $user->tokens()->delete();
                $user->deviceTokens()->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Map an unknown email (INVALID_USER) to the same generic message as
            // an invalid/expired token so this endpoint can't be used to
            // enumerate which email addresses have an account.
            throw ValidationException::withMessages([
                'email' => [__(Password::INVALID_TOKEN)],
            ]);
        }

        return response()->json([
            'message' => __($status),
        ]);
    }
}
