<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExportUserData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class AccountExportController extends Controller
{
    #[OA\Post(
        path: '/api/account/export',
        summary: 'Request a personal data export',
        description: 'Queues a GDPR data export for the authenticated user. A download link is emailed to the user once the export file has been generated. The link expires after a short window.',
        tags: ['Account'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 202, description: 'Export queued'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ],
    )]
    public function __invoke(Request $request): Response
    {
        ExportUserData::dispatch($request->user());

        return response()->noContent(Response::HTTP_ACCEPTED);
    }
}
