<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/** @mixin User */
#[OA\Schema(
    schema: 'User',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'username', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
        new OA\Property(property: 'avatar', type: 'string', nullable: true),
        new OA\Property(property: 'bio', type: 'string', nullable: true),
        new OA\Property(property: 'locale', type: 'string', nullable: true),
        new OA\Property(property: 'feed_layout', type: 'string', enum: ['list', 'masonry'], nullable: true, description: 'Preferred home feed layout. Only present for the authenticated user. Null means not chosen yet; the client then defaults to masonry and shows a one-time chooser.'),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'email_verified', type: 'boolean', description: 'Whether the email address is verified. Only present for the authenticated user.'),
        new OA\Property(property: 'email_verification_required', type: 'boolean', description: 'Whether this account must verify its email before accessing the app. Grandfathered accounts return false. Only present for the authenticated user.'),
        new OA\Property(property: 'onboarded_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'onboarding_step', type: 'string', enum: ['intro', 'first_circle', 'add_children', 'invite_members', 'notifications'], nullable: true, description: 'Furthest completed onboarding step, so the client can resume mid-flow. Only present for the authenticated user while not yet onboarded; null otherwise.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
)]
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // E-mail en e-mailverificatiestatus zijn PII die alleen het account
        // zelf hoort te zien. Deze resource wordt overal hergebruikt (op
        // comment.user, like.user, post.user) — zonder deze scoping lekken
        // we elk e-mailadres aan elke geauthenticeerde caller.
        $isSelf = $request->user()?->id === $this->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $isSelf ? $this->email : null,
            'avatar' => MediaUrl::sign($this->avatar),
            'bio' => $this->bio,
            'locale' => $this->locale,
            'feed_layout' => $isSelf ? $this->feed_layout?->value : null,
            'email_verified_at' => $isSelf ? $this->email_verified_at : null,
            'email_verified' => $isSelf ? $this->email_verified_at !== null : null,
            'email_verification_required' => $isSelf ? $this->requiresEmailVerification() : null,
            'onboarded_at' => $isSelf ? $this->onboarded_at : null,
            // Computed only for the not-yet-onboarded account itself: this
            // resource is reused on every nested user (comments, posts) and
            // must not cost an onboarding-steps query there.
            'onboarding_step' => $isSelf && $this->onboarded_at === null
                ? $this->furthestOnboardingStep()?->value
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
