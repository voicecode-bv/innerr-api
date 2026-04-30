<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MollieWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Mollie webhook handler is not yet implemented (Phase 1).',
        ], 501);
    }
}
