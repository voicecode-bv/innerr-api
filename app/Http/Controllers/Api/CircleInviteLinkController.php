<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCircleInviteLinkRequest;
use App\Http\Resources\CircleInviteLinkPreviewResource;
use App\Http\Resources\CircleInviteLinkResource;
use App\Models\Circle;
use App\Models\CircleInviteLink;
use App\Models\CircleInviteLinkRedemption;
use App\Notifications\CircleMemberJoinedNotification;
use App\Services\MemberPersonSyncer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class CircleInviteLinkController extends Controller
{
    use AuthorizesRequests;

    #[OA\Get(
        path: '/api/circles/{circle}/invite-links',
        summary: 'List invite links',
        description: 'List shareable invite links for a circle. Available to the circle owner, and to members when `members_can_invite` is enabled.',
        tags: ['Circle Invite Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of active invite links',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CircleInviteLink')),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function index(Request $request, Circle $circle): AnonymousResourceCollection
    {
        $this->authorize('manageInviteLinks', $circle);

        $links = $circle->inviteLinks()
            ->with('createdBy:id,name,username')
            ->whereNull('revoked_at')
            ->latest()
            ->get();

        return CircleInviteLinkResource::collection($links);
    }

    #[OA\Post(
        path: '/api/circles/{circle}/invite-links',
        summary: 'Create invite link',
        description: 'Create a new shareable invite link for a circle. Available to the circle owner, and to members when `members_can_invite` is enabled. Defaults to no expiry and unlimited uses.',
        tags: ['Circle Invite Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'expires_in_days', type: 'integer', nullable: true, description: 'Lifetime in days. Defaults to null (never expires).', example: null),
                new OA\Property(property: 'max_uses', type: 'integer', nullable: true, description: 'Maximum number of redemptions. Defaults to null (unlimited).', example: null),
            ]),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Invite link created',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/CircleInviteLink'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error'),
        ],
    )]
    public function store(StoreCircleInviteLinkRequest $request, Circle $circle): JsonResponse
    {
        $this->authorize('manageInviteLinks', $circle);

        $expiresInDays = $request->exists('expires_in_days')
            ? $request->validated('expires_in_days')
            : null;

        $link = CircleInviteLink::create([
            'circle_id' => $circle->id,
            'created_by_user_id' => $request->user()->id,
            'token' => $this->generateUniqueToken(),
            'expires_at' => $expiresInDays === null ? null : now()->addDays((int) $expiresInDays),
            'max_uses' => $request->validated('max_uses'),
            'uses_count' => 0,
        ]);

        $link->load('createdBy:id,name,username');

        return (new CircleInviteLinkResource($link))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Delete(
        path: '/api/circles/{circle}/invite-links/{circleInviteLink}',
        summary: 'Revoke invite link',
        description: 'Revoke a shareable invite link. The link is soft-revoked (marked with `revoked_at`) so existing redemptions remain auditable. Available to the link creator and the circle owner.',
        tags: ['Circle Invite Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'circle', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'circleInviteLink', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Link revoked'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Link not found'),
        ],
    )]
    public function destroy(Request $request, Circle $circle, CircleInviteLink $circleInviteLink): JsonResponse
    {
        if ($circleInviteLink->circle_id !== $circle->id) {
            abort(404);
        }

        $userId = $request->user()->id;

        if ($circle->user_id !== $userId && $circleInviteLink->created_by_user_id !== $userId) {
            abort(403);
        }

        if ($circleInviteLink->revoked_at === null) {
            $circleInviteLink->update(['revoked_at' => now()]);
        }

        return response()->json(null, 204);
    }

    #[OA\Get(
        path: '/api/invite-links/{token}',
        summary: 'Preview invite link (public)',
        description: 'Public, unauthenticated preview of an invite link. Returns enough information to display a landing page (circle name + photo, inviter, member preview) without revealing private circle data. Reports validity reason when the link is no longer usable.',
        tags: ['Circle Invite Links'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Invite link preview',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', ref: '#/components/schemas/CircleInviteLinkPreview'),
                ]),
            ),
            new OA\Response(response: 404, description: 'Link not found'),
        ],
    )]
    public function show(string $token): CircleInviteLinkPreviewResource
    {
        $link = CircleInviteLink::query()
            ->where('token', $token)
            ->with([
                'circle' => fn ($q) => $q->withCount('members'),
                'circle.members' => fn ($q) => $q->limit(3),
                'createdBy:id,name,username,avatar',
            ])
            ->firstOrFail();

        return new CircleInviteLinkPreviewResource($link);
    }

    #[OA\Post(
        path: '/api/invite-links/{token}/accept',
        summary: 'Accept invite link',
        description: 'Redeem an invite link and join the circle as the authenticated user. Idempotent: if the user is already a member, returns success without incrementing the use counter.',
        tags: ['Circle Invite Links'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Link accepted',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Joined circle.'),
                    new OA\Property(property: 'already_member', type: 'boolean'),
                    new OA\Property(property: 'circle', type: 'object', properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'name', type: 'string'),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 410, description: 'Link expired, revoked, or maxed out'),
            new OA\Response(response: 404, description: 'Link not found'),
        ],
    )]
    public function accept(Request $request, string $token, MemberPersonSyncer $memberPersons): JsonResponse
    {
        $user = $request->user();

        /** @var array{circle: Circle, joined: bool} $result */
        $result = DB::transaction(function () use ($token, $user, $memberPersons) {
            $link = CircleInviteLink::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->firstOrFail();

            $circle = $link->circle()->first();

            $alreadyMember = $user->id === $circle->user_id
                || $circle->members()->whereKey($user->id)->exists();

            if ($alreadyMember) {
                return ['circle' => $circle, 'joined' => false];
            }

            if (! $link->isUsable()) {
                abort(410, 'This invite link is no longer valid.');
            }

            $circle->members()->syncWithoutDetaching([$user->id]);
            $memberPersons->attach($circle, $user);

            CircleInviteLinkRedemption::create([
                'invite_link_id' => $link->id,
                'user_id' => $user->id,
                'redeemed_at' => now(),
            ]);

            $link->increment('uses_count');

            return ['circle' => $circle, 'joined' => true];
        });

        // Notified after the transaction so the queued job can never observe
        // (or be rolled back with) a half-committed join. When the circle has
        // no posts yet, the notification nudges the owner to share their
        // first moment so the new member does not land on an empty timeline.
        if ($result['joined']) {
            $result['circle']->user?->notify(
                new CircleMemberJoinedNotification($result['circle'], $user),
            );
        }

        return response()->json([
            'message' => $result['joined'] ? 'Joined circle.' : 'Already a member.',
            'already_member' => ! $result['joined'],
            'circle' => [
                'id' => $result['circle']->id,
                'name' => $result['circle']->name,
            ],
        ]);
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(43);
        } while (CircleInviteLink::where('token', $token)->exists());

        return $token;
    }
}
