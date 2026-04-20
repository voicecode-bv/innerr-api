<?php

namespace App\Http\Controllers\Api;

use App\Actions\AnonymizeUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class AccountController extends Controller
{
    #[OA\Delete(
        path: '/api/account',
        summary: 'Delete account',
        description: 'Anonymize the authenticated user and irreversibly remove all of their personal data (GDPR right to erasure). Strips profile identifiers, revokes every access token, deletes owned circles, wipes uploaded media, and detaches memberships. Posts and comments remain attached to the anonymized user-row so other users\' discussions stay intact.',
        tags: ['Account'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 204, description: 'Account anonymized'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ],
    )]
    public function __invoke(Request $request, AnonymizeUser $anonymize): Response
    {
        $anonymize($request->user());

        return response()->noContent();
    }
}
