<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Google Pub/Sub RTDN handler is not yet implemented (Phase 3).',
        ], 501);
    }
}
