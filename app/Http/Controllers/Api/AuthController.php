<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(
        private EmailVerificationService $verification,
    ) {}

    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Login',
        description: 'Authenticate a user and return a Sanctum token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password', 'device_name'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', example: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'iPhone 15'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful login',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Invalid credentials'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Sanctum heeft het token van deze request nog niet gezien (het wordt
        // hieronder pas aangemaakt), dus `$request->user()` is null als
        // UserResource zelf moet bepalen of de caller de eigenaar is en email
        // mag zien. Expliciet zetten zodat de PII-scoping in UserResource
        // (`$isSelf`) klopt.
        Auth::setUser($user);

        return response()->json([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/register',
        summary: 'Register',
        description: 'Create a new user account and return a Sanctum token.',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'username', 'email', 'password', 'password_confirmation', 'device_name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'username', type: 'string', example: 'johndoe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', minLength: 8, example: 'password'),
                    new OA\Property(property: 'password_confirmation', type: 'string', example: 'password'),
                    new OA\Property(property: 'device_name', type: 'string', example: 'iPhone 15'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Successfully registered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'locale' => app()->getLocale(),
        ]);

        CircleInvitation::where('email', strtolower($user->email))
            ->where('status', InvitationStatus::Pending)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        // Zie login(): zonder gezette auth user laat UserResource email
        // zien als null vanwege de PII-scoping.
        Auth::setUser($user);

        // Verstuur direct een verificatiecode zodat die al klaarstaat tegen de
        // tijd dat de gebruiker de onboarding heeft doorlopen.
        $this->verification->send($user);

        return response()->json([
            'token' => $user->createToken($request->device_name)->plainTextToken,
            'user' => new UserResource($user),
        ], 201);
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Current user',
        description: 'Return the authenticated user.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Logout',
        description: 'Revoke the current access token.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Successfully logged out'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
