<?php

use App\Enums\CircleMemberRole;
use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleOwnershipTransfer;
use App\Models\User;

it('lets the owner promote a member to administrator', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $member = User::factory()->create();
    $circle->members()->attach($member);

    $this->actingAs($owner)
        ->putJson("/api/circles/{$circle->id}/members/{$member->id}/role", [
            'role' => CircleMemberRole::Administrator->value,
        ])
        ->assertOk()
        ->assertJsonPath('role', CircleMemberRole::Administrator->value);

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $member->id,
        'role' => CircleMemberRole::Administrator->value,
    ]);
});

it('lets the owner demote an administrator back to member', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);

    $this->actingAs($owner)
        ->putJson("/api/circles/{$circle->id}/members/{$admin->id}/role", [
            'role' => CircleMemberRole::Member->value,
        ])
        ->assertOk();

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $admin->id,
        'role' => CircleMemberRole::Member->value,
    ]);
});

it('lets an administrator promote another member', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $other = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);
    $circle->members()->attach($other);

    $this->actingAs($admin)
        ->putJson("/api/circles/{$circle->id}/members/{$other->id}/role", [
            'role' => CircleMemberRole::Administrator->value,
        ])
        ->assertOk();

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $other->id,
        'role' => CircleMemberRole::Administrator->value,
    ]);
});

it('forbids a plain member from changing roles', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $circle->members()->attach([$member->id, $other->id]);

    $this->actingAs($member)
        ->putJson("/api/circles/{$circle->id}/members/{$other->id}/role", [
            'role' => CircleMemberRole::Administrator->value,
        ])
        ->assertForbidden();
});

it('rejects changing the owner role via the role endpoint', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->putJson("/api/circles/{$circle->id}/members/{$owner->id}/role", [
            'role' => CircleMemberRole::Administrator->value,
        ])
        ->assertStatus(422);
});

it('rejects role updates for non-members', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $stranger = User::factory()->create();

    $this->actingAs($owner)
        ->putJson("/api/circles/{$circle->id}/members/{$stranger->id}/role", [
            'role' => CircleMemberRole::Administrator->value,
        ])
        ->assertStatus(422);
});

it('validates the role value', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $member = User::factory()->create();
    $circle->members()->attach($member);

    $this->actingAs($owner)
        ->putJson("/api/circles/{$circle->id}/members/{$member->id}/role", [
            'role' => 'super-admin',
        ])
        ->assertStatus(422);
});

it('lets an administrator update the circle and its settings', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['name' => 'Original']);
    $admin = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);

    $this->actingAs($admin)
        ->putJson("/api/circles/{$circle->id}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $this->actingAs($admin)
        ->putJson("/api/circles/{$circle->id}/settings", [
            'members_can_invite' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.members_can_invite', true);
});

it('forbids an administrator from deleting the circle', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);

    $this->actingAs($admin)
        ->deleteJson("/api/circles/{$circle->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('circles', ['id' => $circle->id]);
});

it('lets an administrator invite and remove members', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $invitee = User::factory()->create();
    $existingMember = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);
    $circle->members()->attach($existingMember);

    $this->actingAs($admin)
        ->postJson("/api/circles/{$circle->id}/members", [
            'username' => $invitee->username,
        ])
        ->assertCreated();

    $this->actingAs($admin)
        ->deleteJson("/api/circles/{$circle->id}/members/{$existingMember->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $existingMember->id,
    ]);
});

it('forbids an administrator from transferring ownership', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $target = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);
    $circle->members()->attach($target);

    $this->actingAs($admin)
        ->postJson("/api/circles/{$circle->id}/ownership-transfer", [
            'user_id' => $target->id,
        ])
        ->assertForbidden();
});

it('exposes is_administrator and per-member role in the show response', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);
    $circle->members()->attach($member);

    $response = $this->actingAs($admin)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonPath('data.is_owner', false)
        ->assertJsonPath('data.is_administrator', true);

    $members = collect($response->json('data.members'))->keyBy('id');
    expect($members[$owner->id]['role'])->toBe('owner');
    expect($members[$admin->id]['role'])->toBe(CircleMemberRole::Administrator->value);
    expect($members[$member->id]['role'])->toBe(CircleMemberRole::Member->value);
});

it('exposes is_administrator on the index response', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();

    $adminCircle = Circle::factory()->for($owner)->create();
    $adminCircle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);

    $memberCircle = Circle::factory()->for($owner)->create();
    $memberCircle->members()->attach($admin);

    $response = $this->actingAs($admin)
        ->getJson('/api/circles')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$adminCircle->id]['is_administrator'])->toBeTrue();
    expect($byId[$memberCircle->id]['is_administrator'])->toBeFalse();
});

it('reports is_administrator false for the owner', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonPath('data.is_owner', true)
        ->assertJsonPath('data.is_administrator', false);
});

it('lets an administrator see all members regardless of members_can_view_members', function () {
    $owner = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_view_members' => false]);
    $admin = User::factory()->create();
    $other = User::factory()->create();
    $circle->members()->attach($admin, ['role' => CircleMemberRole::Administrator->value]);
    $circle->members()->attach($other);

    $this->actingAs($admin)
        ->getJson("/api/circles/{$circle->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data.members');
});

it('demotes the previous owner to a plain member after ownership transfer', function () {
    $previousOwner = User::factory()->create();
    $newOwner = User::factory()->create();
    $circle = Circle::factory()->for($previousOwner)->create();
    $circle->members()->attach($newOwner, ['role' => CircleMemberRole::Administrator->value]);

    $transfer = CircleOwnershipTransfer::create([
        'circle_id' => $circle->id,
        'from_user_id' => $previousOwner->id,
        'to_user_id' => $newOwner->id,
        'status' => InvitationStatus::Pending,
    ]);

    $this->actingAs($newOwner)
        ->postJson("/api/circle-ownership-transfers/{$transfer->id}/accept")
        ->assertOk();

    $this->assertDatabaseHas('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $previousOwner->id,
        'role' => CircleMemberRole::Member->value,
    ]);

    $this->assertDatabaseMissing('circle_user', [
        'circle_id' => $circle->id,
        'user_id' => $newOwner->id,
    ]);
});
