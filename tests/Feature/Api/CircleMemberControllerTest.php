<?php

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;

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

it('adds a member directly when they have previously accepted an invitation', function () {
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
        ->assertJsonPath('message', 'Member added.');

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $invitee->id,
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
