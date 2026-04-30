<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyApplePurchaseRequest;
use Illuminate\Http\JsonResponse;

class AppleVerifyController extends Controller
{
    public function __invoke(VerifyApplePurchaseRequest $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Apple IAP verification is not yet implemented (Phase 2).',
        ], 501);
    }
}
