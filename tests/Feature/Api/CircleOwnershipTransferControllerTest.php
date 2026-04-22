<?php

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleOwnershipTransfer;
use App\Models\User;
use App\Notifications\CircleOwnershipTransferAcceptedNotification;
use App\Notifications\CircleOwnershipTransferDeclinedNotification;
use App\Notifications\CircleOwnershipTransferRequestedNotification;
use Illuminate\Support\Facades\Notification;

it('lists pending ownership transfers offered to the authenticated user', function () {
    $user = User::factory()->create();

    $pending = CircleOwnershipTransfer::factory()->create([
        'to_user_id' => $user->id,
        'status' => InvitationStatus::Pending,
    ]);

    CircleOwnershipTransfer::factory()->accepted()->create([
        'to_user_id' => $user->id,
    ]);

    CircleOwnershipTransfer::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($user)
        ->getJson('/api/circle-ownership-transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pending->id);
});

it('lets the owner request an ownership transfer to a member', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $member->id,
        ])
        ->assertCreated()
        ->assertJsonPath('message', 'Ownership transfer requested.');

    $this->assertDatabaseHas('circle_ownership_transfers', [
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending->value,
    ]);

    Notification::assertSentTo($member, CircleOwnershipTransferRequestedNotification::class);
});

it('does not allow non-owners to request an ownership transfer', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $this->actingAs($member)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $member->id,
        ])
        ->assertForbidden();
});

it('rejects transfer to a user that is not a member', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $stranger->id,
        ])
        ->assertStatus(422);
});

it('rejects transfer to the current owner', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $owner->id,
        ])
        ->assertStatus(422);
});

it('rejects a second pending transfer for the same circle', function () {
    $owner = User::factory()->create();
    $memberA = User::factory()->create();
    $memberB = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach([$memberA->id, $memberB->id]);

    CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $memberA->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $memberB->id,
        ])
        ->assertStatus(409);
});

it('allows owner to cancel a pending transfer', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $transfer = CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->deleteJson("/api/circles/{$circle->id}/ownership-transfer/{$transfer->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('circle_ownership_transfers', ['id' => $transfer->id]);
});

it('accepts a pending transfer, swaps ownership, and notifies the previous owner', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $transfer = CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($member)
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/accept")
        ->assertOk()
        ->assertJsonPath('message', 'Ownership transfer accepted.');

    expect($transfer->fresh()->status)->toBe(InvitationStatus::Accepted);
    expect($circle->fresh()->user_id)->toBe($member->id);

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $owner->id,
    ]);

    $this->assertDatabaseMissing('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $member->id,
    ]);

    Notification::assertSentTo($owner, CircleOwnershipTransferAcceptedNotification::class);
});

it('cannot accept another users transfer', function () {
    $transfer = CircleOwnershipTransfer::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/accept")
        ->assertForbidden();
});

it('cannot accept an already accepted transfer', function () {
    $user = User::factory()->create();
    $transfer = CircleOwnershipTransfer::factory()->accepted()->create([
        'to_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/accept")
        ->assertForbidden();
});

it('declines a pending transfer without swapping ownership and notifies the owner', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $transfer = CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($member)
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/decline")
        ->assertOk()
        ->assertJsonPath('message', 'Ownership transfer declined.');

    expect($transfer->fresh()->status)->toBe(InvitationStatus::Declined);
    expect($circle->fresh()->user_id)->toBe($owner->id);

    Notification::assertSentTo($owner, CircleOwnershipTransferDeclinedNotification::class);
});

it('cannot decline another users transfer', function () {
    $transfer = CircleOwnershipTransfer::factory()->create([
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/decline")
        ->assertForbidden();
});

it('exposes pending ownership transfer on the circle detail for the owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $transfer = CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonPath('data.pending_ownership_transfer.id', $transfer->id)
        ->assertJsonPath('data.pending_ownership_transfer.to_user.id', $member->id)
        ->assertJsonPath('data.pending_ownership_transfer.from_user.id', $owner->id);
});

it('exposes pending ownership transfer on the circle detail for the target', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    $transfer = CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($member)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonPath('data.pending_ownership_transfer.id', $transfer->id);
});

it('hides pending ownership transfer from uninvolved members', function () {
    $owner = User::factory()->create();
    $target = User::factory()->create();
    $bystander = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach([$target->id, $bystander->id]);

    CircleOwnershipTransfer::factory()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $target->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($bystander)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonPath('data.pending_ownership_transfer', null);
});

it('allows a new transfer after a previous one was declined', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $circle->members()->attach($member);

    CircleOwnershipTransfer::factory()->declined()->create([
        'circle_id' => $circle->id,
        'from_user_id' => $owner->id,
        'to_user_id' => $member->id,
    ]);

    $this->actingAs($owner)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $member->id,
        ])
        ->assertCreated();
});
