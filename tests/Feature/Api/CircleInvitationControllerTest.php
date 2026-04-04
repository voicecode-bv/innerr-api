<?php

use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\User;
use App\Notifications\CircleInvitationAcceptedNotification;
use App\Notifications\CircleInvitationNotification;
use Illuminate\Support\Facades\Notification;

it('can list pending invitations for a circle', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    CircleInvitation::factory()->count(2)->create([
        'circle_id' => $circle->id,
        'invited_by' => $user->id,
    ]);

    // Accepted invitation should not appear
    CircleInvitation::factory()->accepted()->create([
        'circle_id' => $circle->id,
        'invited_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson("/api/circles/{$circle->id}/invitations")
        ->assertSuccessful()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'email', 'created_at'],
            ],
        ]);
});

it('cannot list invitations for a circle you do not own', function () {
    $circle = Circle::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/circles/{$circle->id}/invitations")
        ->assertForbidden();
});

it('can cancel a pending invitation', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $invitation = CircleInvitation::factory()->create([
        'circle_id' => $circle->id,
        'invited_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/circles/{$circle->id}/invitations/{$invitation->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('circle_invitations', ['id' => $invitation->id]);
});

it('cannot cancel an invitation for a circle you do not own', function () {
    $invitation = CircleInvitation::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/circles/{$invitation->circle_id}/invitations/{$invitation->id}")
        ->assertForbidden();
});

it('returns not found when cancelling invitation from wrong circle', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $otherCircle = Circle::factory()->create(['user_id' => $user->id]);
    $invitation = CircleInvitation::factory()->create([
        'circle_id' => $otherCircle->id,
        'invited_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->deleteJson("/api/circles/{$circle->id}/invitations/{$invitation->id}")
        ->assertNotFound();
});

it('can invite someone to a circle', function () {
    Notification::fake();

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/circles/{$circle->id}/invitations", [
            'email' => 'friend@example.com',
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Invitation sent.');

    $this->assertDatabaseHas('circle_invitations', [
        'circle_id' => $circle->id,
        'invited_by' => $user->id,
        'email' => 'friend@example.com',
        'accepted_at' => null,
    ]);

    Notification::assertSentOnDemand(CircleInvitationNotification::class);
});

it('cannot invite to a circle you do not own', function () {
    $circle = Circle::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circles/{$circle->id}/invitations", [
            'email' => 'friend@example.com',
        ])
        ->assertForbidden();
});

it('cannot send duplicate invitations', function () {
    Notification::fake();

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    CircleInvitation::factory()->create([
        'circle_id' => $circle->id,
        'invited_by' => $user->id,
        'email' => 'friend@example.com',
    ]);

    $this->actingAs($user)
        ->postJson("/api/circles/{$circle->id}/invitations", [
            'email' => 'friend@example.com',
        ])
        ->assertStatus(409);
});

it('cannot invite an existing member', function () {
    Notification::fake();

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $member = User::factory()->create(['email' => 'member@example.com']);
    $circle->members()->attach($member);

    $this->actingAs($user)
        ->postJson("/api/circles/{$circle->id}/invitations", [
            'email' => 'member@example.com',
        ])
        ->assertStatus(409);
});

it('validates invitation email', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/circles/{$circle->id}/invitations", [
            'email' => 'not-an-email',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('requires authentication to invite', function () {
    $circle = Circle::factory()->create();

    $this->postJson("/api/circles/{$circle->id}/invitations", [
        'email' => 'friend@example.com',
    ])->assertUnauthorized();
});

it('can accept an invitation', function () {
    Notification::fake();

    $invitation = CircleInvitation::factory()->create();
    $user = User::factory()->create(['email' => $invitation->email]);

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->token}/accept")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Invitation accepted.');

    $invitation->refresh();

    expect($invitation->accepted_at)->not->toBeNull();

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $invitation->circle_id,
        'user_id' => $user->id,
    ]);

    Notification::assertSentTo(
        $invitation->inviter,
        CircleInvitationAcceptedNotification::class,
    );
});

it('cannot accept an already accepted invitation', function () {
    $invitation = CircleInvitation::factory()->accepted()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/circle-invitations/{$invitation->token}/accept")
        ->assertStatus(410);
});

it('returns not found for invalid token', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/circle-invitations/invalid-token/accept')
        ->assertNotFound();
});

it('requires authentication to accept', function () {
    $invitation = CircleInvitation::factory()->create();

    $this->postJson("/api/circle-invitations/{$invitation->token}/accept")
        ->assertUnauthorized();
});

it('auto-accepts pending invitations on registration', function () {
    Notification::fake();

    $invitation = CircleInvitation::factory()->create([
        'email' => 'newuser@example.com',
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'New User',
        'username' => 'newuser',
        'email' => 'newuser@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated();

    $invitation->refresh();

    expect($invitation->accepted_at)->not->toBeNull();

    $user = User::where('email', 'newuser@example.com')->first();

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $invitation->circle_id,
        'user_id' => $user->id,
    ]);

    Notification::assertSentTo(
        $invitation->inviter,
        CircleInvitationAcceptedNotification::class,
    );
});
