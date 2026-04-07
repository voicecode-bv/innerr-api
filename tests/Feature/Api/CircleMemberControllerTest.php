<?php

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Notifications\CircleInvitationNotification;
use App\Notifications\CircleMemberInvitedByMemberNotification;
use Illuminate\Support\Facades\Notification;

it('sends an invitation instead of adding a member directly', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Invitation sent.');

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
        'inviter_id' => $owner->id,
        'status' => InvitationStatus::Pending->value,
    ]);

    $this->assertDatabaseMissing('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
    ]);
});

it('sends a new invitation even when user has previously accepted one for the same circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $invitee = User::factory()->create();

    CircleInvitation::factory()->create([
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
        'inviter_id' => $owner->id,
        'status' => InvitationStatus::Accepted,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Invitation sent.');

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
        'status' => InvitationStatus::Pending->value,
    ]);
});

it('does not create duplicate pending invitations', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    expect(CircleInvitation::where('circle_id', $circle->id)
        ->where('user_id', $invitee->id)
        ->where('status', InvitationStatus::Pending)
        ->count())->toBe(1);
});

it('requires circle ownership to invite a member', function () {
    $circle = Circle::factory()->create();
    $invitee = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertForbidden();
});

it('forbids members from inviting when members_can_invite is false', function () {
    $circle = Circle::factory()->create(['members_can_invite' => false]);
    $member = User::factory()->create();
    $circle->members()->attach($member);
    $invitee = User::factory()->create();

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertForbidden();
});

it('allows members to invite when members_can_invite is true', function () {
    $circle = Circle::factory()->create(['members_can_invite' => true]);
    $member = User::factory()->create();
    $circle->members()->attach($member);
    $invitee = User::factory()->create();

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
        'inviter_id' => $member->id,
    ]);
});

it('notifies the circle owner when a member invites someone else', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
    $member = User::factory()->create();
    $circle->members()->attach($member);
    $invitee = User::factory()->create();

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    Notification::assertSentTo($owner, CircleMemberInvitedByMemberNotification::class);
});

it('does not notify the owner when the owner invites someone', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $invitee = User::factory()->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    Notification::assertNotSentTo($owner, CircleMemberInvitedByMemberNotification::class);
});

it('forbids non-members from inviting even when members_can_invite is true', function () {
    $circle = Circle::factory()->create(['members_can_invite' => true]);
    $invitee = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertForbidden();
});

it('validates username is required', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('validates username must exist', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => 'nonexistent',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('can invite by email and creates invitation with token', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'newperson@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Invitation sent.');

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'email' => 'newperson@example.com',
        'inviter_id' => $owner->id,
        'user_id' => null,
        'status' => InvitationStatus::Pending->value,
    ]);

    $invitation = CircleInvitation::where('email', 'newperson@example.com')->first();
    expect($invitation->token)->not->toBeNull();
});

it('sends an email notification when inviting by email', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'newperson@example.com',
        ])
        ->assertCreated();

    Notification::assertSentOnDemand(
        CircleInvitationNotification::class,
        function (CircleInvitationNotification $notification, array $channels, object $notifiable) {
            return $notifiable->routes['mail'] === 'newperson@example.com';
        },
    );
});

it('sets user_id when inviting by email for an existing user', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'existing@example.com',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'email' => 'existing@example.com',
        'user_id' => $existingUser->id,
        'status' => InvitationStatus::Pending->value,
    ]);
});

it('does not create duplicate pending email invitations', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'duplicate@example.com',
        ])
        ->assertCreated();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'duplicate@example.com',
        ])
        ->assertCreated();

    expect(CircleInvitation::where('circle_id', $circle->id)
        ->where('email', 'duplicate@example.com')
        ->where('status', InvitationStatus::Pending)
        ->count())->toBe(1);
});

it('validates that at least username or email is required', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'email']);
});

it('validates email format', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/members", [
            'email' => 'not-an-email',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('forbids the owner from removing themselves from their circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->deleteJson("/api/circles/{$circle->id}/members/{$owner->id}")
        ->assertForbidden();
});

it('can remove a member from a circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $member = User::factory()->create();
    $circle->members()->attach($member);

    $this->actingAs($owner)
        ->deleteJson("/api/circles/{$circle->id}/members/{$member->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $member->id,
    ]);
});
