<?php

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;

it('can list pending invitations for the authenticated user', function () {
    $user = User::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'status' => InvitationStatus::Pending,
    ]);

    // Create an accepted invitation that should not show up
    CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'status' => InvitationStatus::Accepted,
    ]);

    // Create an invitation for another user that should not show up
    CircleInvitation::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($user)
        ->getJson('/api/circle-invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invitation->id);
});

it('can accept a pending invitation', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'circle_id' => $circle->id,
        'user_id' => $user->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->id}/accept")
        ->assertOk()
        ->assertJsonPath('message', 'Invitation accepted.');

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Accepted);

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $user->id,
    ]);
});

it('can decline a pending invitation', function () {
    $user = User::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->id}/decline")
        ->assertOk()
        ->assertJsonPath('message', 'Invitation declined.');

    expect($invitation->fresh()->status)->toBe(InvitationStatus::Declined);
});

it('cannot accept another users invitation', function () {
    $invitation = CircleInvitation::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circle-invitations/{$invitation->id}/accept")
        ->assertForbidden();
});

it('cannot decline another users invitation', function () {
    $invitation = CircleInvitation::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circle-invitations/{$invitation->id}/decline")
        ->assertForbidden();
});

it('cannot accept an already accepted invitation', function () {
    $user = User::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'status' => InvitationStatus::Accepted,
    ]);

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->id}/accept")
        ->assertForbidden();
});

it('cannot accept an already declined invitation', function () {
    $user = User::factory()->create();
    $invitation = CircleInvitation::factory()->create([
        'user_id' => $user->id,
        'status' => InvitationStatus::Declined,
    ]);

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->id}/accept")
        ->assertForbidden();
});
