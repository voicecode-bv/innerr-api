<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyGooglePurchaseRequest;
use Illuminate\Http\JsonResponse;

class GoogleVerifyController extends Controller
{
    public function __invoke(VerifyGooglePurchaseRequest $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Google IAP verification is not yet implemented (Phase 3).',
        ], 501);
    }
}
