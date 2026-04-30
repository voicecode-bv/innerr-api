<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateMollieCheckoutRequest;
use Illuminate\Http\JsonResponse;

class MollieCheckoutController extends Controller
{
    public function __invoke(CreateMollieCheckoutRequest $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Mollie checkout is not yet implemented (Phase 1).',
        ], 501);
    }
}
