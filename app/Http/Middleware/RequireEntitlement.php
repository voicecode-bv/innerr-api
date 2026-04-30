<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireEntitlement
{
    public function handle(Request $request, Closure $next, string $entitlement): Response
    {
        $user = $request->user();

        if (! $user) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->hasEntitlement($entitlement)) {
            return new JsonResponse([
                'message' => 'This feature requires a higher subscription tier.',
                'required_entitlement' => $entitlement,
                'upgrade_url' => route('api.subscription.plans.index'),
            ], 402);
        }

        return $next($request);
    }
}
