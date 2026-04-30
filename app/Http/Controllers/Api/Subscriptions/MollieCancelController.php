<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MollieCancelController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Mollie cancel is not yet implemented (Phase 1).',
        ], 501);
    }
}
