<?php

use App\Models\Circle;
use App\Models\CircleInviteLink;
use App\Models\CircleInviteLinkRedemption;
use App\Models\User;
use App\Notifications\CircleMemberJoinedNotification;
use Illuminate\Support\Facades\Notification;

it('lets the owner create an invite link with defaults', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $response = $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/invite-links")
        ->assertCreated();

    $response->assertJsonPath('data.uses_count', 0);
    $response->assertJsonPath('data.max_uses', null);
    $response->assertJsonPath('data.expires_at', null);
    $response->assertJsonPath('data.revoked_at', null);

    $url = $response->json('data.url');
    expect($url)->toStartWith(rtrim(config('app.frontend_url'), '/').'/join/');

    expect($circle->inviteLinks()->count())->toBe(1);
});

it('lets a member create an invite link when members_can_invite is true', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
    $circle->members()->attach($member);

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/invite-links")
        ->assertCreated();
});

it('forbids non-members from creating invite links', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($stranger)
        ->postJson("/api/circles/{$circle->id}/invite-links")
        ->assertForbidden();
});

it('forbids members from creating invite links when members_can_invite is false', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => false]);
    $circle->members()->attach($member);

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/invite-links")
        ->assertForbidden();
});

it('respects expires_in_days and max_uses options', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $response = $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/invite-links", [
            'expires_in_days' => 30,
            'max_uses' => 5,
        ])
        ->assertCreated();

    $response->assertJsonPath('data.max_uses', 5);

    $link = CircleInviteLink::firstOrFail();
    expect($link->expires_at)->not->toBeNull()
        ->and((int) round(now()->diffInDays($link->expires_at, true)))->toBe(30);
});

it('allows null expiry', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/invite-links", ['expires_in_days' => null])
        ->assertCreated();

    expect(CircleInviteLink::firstOrFail()->expires_at)->toBeNull();
});

it('lists only active invite links for the circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $active = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);
    CircleInviteLink::factory()->revoked()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $response = $this->actingAs($owner)
        ->getJson("/api/circles/{$circle->id}/invite-links")
        ->assertOk();

    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $active->id);
});

it('lets the owner revoke any invite link in their circle', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
    $circle->members()->attach($member);

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $member->id,
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/circles/{$circle->id}/invite-links/{$link->id}")
        ->assertNoContent();

    expect($link->fresh()->revoked_at)->not->toBeNull();
});

it('lets the creator revoke their own invite link', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
    $circle->members()->attach($member);

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $member->id,
    ]);

    $this->actingAs($member)
        ->deleteJson("/api/circles/{$circle->id}/invite-links/{$link->id}")
        ->assertNoContent();
});

it('forbids other members from revoking someone elses link', function () {
    $owner = User::factory()->create();
    $creator = User::factory()->create();
    $other = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
    $circle->members()->attach([$creator->id, $other->id]);

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $creator->id,
    ]);

    $this->actingAs($other)
        ->deleteJson("/api/circles/{$circle->id}/invite-links/{$link->id}")
        ->assertForbidden();
});

it('returns 404 when revoking a link that does not belong to the circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $otherCircle = Circle::factory()->for($owner)->create();

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $otherCircle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/circles/{$circle->id}/invite-links/{$link->id}")
        ->assertNotFound();
});

it('publicly shows a valid invite link preview', function () {
    $owner = User::factory()->create(['name' => 'Sam']);
    $circle = Circle::factory()->for($owner)->create(['name' => 'Family']);
    $circle->members()->attach(User::factory()->count(2)->create());

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $response = $this->getJson("/api/invite-links/{$link->token}")->assertOk();

    $response->assertJsonPath('data.valid', true);
    $response->assertJsonPath('data.reason', null);
    $response->assertJsonPath('data.circle.name', 'Family');
    $response->assertJsonPath('data.circle.members_count', 3);
    $response->assertJsonPath('data.inviter.name', 'Sam');
});

it('preview reports expired reason', function () {
    $link = CircleInviteLink::factory()->expired()->create();

    $response = $this->getJson("/api/invite-links/{$link->token}")->assertOk();

    $response->assertJsonPath('data.valid', false);
    $response->assertJsonPath('data.reason', 'expired');
});

it('preview reports revoked reason', function () {
    $link = CircleInviteLink::factory()->revoked()->create();

    $this->getJson("/api/invite-links/{$link->token}")
        ->assertOk()
        ->assertJsonPath('data.reason', 'revoked');
});

it('preview reports maxed reason', function () {
    $link = CircleInviteLink::factory()->maxedOut()->create();

    $this->getJson("/api/invite-links/{$link->token}")
        ->assertOk()
        ->assertJsonPath('data.reason', 'maxed');
});

it('returns 404 for unknown token', function () {
    $this->getJson('/api/invite-links/does-not-exist')->assertNotFound();
});

it('lets an authenticated user accept a valid invite link', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['name' => 'Family']);

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $response = $this->actingAs($invitee)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk();

    $response->assertJsonPath('already_member', false);
    $response->assertJsonPath('circle.id', $circle->id);

    expect($link->fresh()->uses_count)->toBe(1);
    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
    ]);
    $this->assertDatabaseHas('circle_invite_link_redemptions', [
        'invite_link_id' => $link->id,
        'user_id' => $invitee->id,
    ]);
});

it('returns already_member without incrementing when user is already in the circle', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($invitee);

    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $this->actingAs($invitee)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk()
        ->assertJsonPath('already_member', true);

    expect($link->fresh()->uses_count)->toBe(0);
});

it('treats the owner as already_member', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk()
        ->assertJsonPath('already_member', true);
});

it('rejects accepting an expired link with 410', function () {
    $link = CircleInviteLink::factory()->expired()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertStatus(410);
});

it('rejects accepting a revoked link with 410', function () {
    $link = CircleInviteLink::factory()->revoked()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertStatus(410);
});

it('rejects accepting a maxed-out link with 410', function () {
    $link = CircleInviteLink::factory()->maxedOut()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertStatus(410);
});

it('requires authentication to accept', function () {
    $link = CircleInviteLink::factory()->create();

    $this->postJson("/api/invite-links/{$link->token}/accept")
        ->assertUnauthorized();
});

it('enforces max_uses across multiple users', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $link = CircleInviteLink::factory()->create([
        'circle_id' => $circle->id,
        'created_by_user_id' => $owner->id,
        'max_uses' => 1,
    ]);

    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk();

    $this->actingAs($second)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertStatus(410);

    expect($link->fresh()->uses_count)->toBe(1);
    expect(CircleInviteLinkRedemption::count())->toBe(1);
});

it('notifies the owner when someone joins through an invite link', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $link = CircleInviteLink::factory()->for($circle)->create(['created_by_user_id' => $owner->id]);
    $joiner = User::factory()->create();

    $this->actingAs($joiner)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk();

    Notification::assertSentTo(
        $owner,
        CircleMemberJoinedNotification::class,
        function (CircleMemberJoinedNotification $notification) use ($circle, $joiner, $owner) {
            // An empty circle marks the join as the first-moment nudge.
            return $notification->circle->is($circle)
                && $notification->joinedUser->is($joiner)
                && $notification->toArray($owner)['circle_was_empty'] === true;
        },
    );
});

it('does not notify the owner when an existing member re-accepts a link', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $link = CircleInviteLink::factory()->for($circle)->create(['created_by_user_id' => $owner->id]);
    $member = User::factory()->create();
    $circle->members()->attach($member);

    $this->actingAs($member)
        ->postJson("/api/invite-links/{$link->token}/accept")
        ->assertOk()
        ->assertJsonPath('already_member', true);

    Notification::assertNothingSent();
});
