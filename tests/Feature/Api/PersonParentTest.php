<?php

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function makeChildWithCircle(User $creator): array
{
    $circle = Circle::factory()->for($creator)->create(['members_can_invite' => true]);
    $person = Person::factory()->create([
        'created_by_user_id' => $creator->id,
        'is_child' => true,
    ]);
    $person->parents()->attach($creator->id);
    $person->circles()->attach($circle);

    return [$circle, $person];
}

it('attaches the creator as first parent when creating a child', function () {
    $creator = User::factory()->create();
    $circle = Circle::factory()->for($creator)->create();

    Sanctum::actingAs($creator);

    $response = $this->postJson('/api/persons', [
        'name' => 'Lotte',
        'is_child' => true,
        'circle_ids' => [$circle->id],
    ])->assertCreated();

    expect($response->json('data.parents.0.id'))->toBe($creator->id);
});

it('no longer lets circle members manage a child via members_can_invite', function () {
    $creator = User::factory()->create();
    [$circle, $child] = makeChildWithCircle($creator);

    $member = User::factory()->create();
    $circle->members()->attach($member);

    Sanctum::actingAs($member);

    $this->putJson("/api/persons/{$child->id}", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('lets the creator add a co-parent who shares a circle with the child', function () {
    $creator = User::factory()->create();
    [$circle, $child] = makeChildWithCircle($creator);

    $partner = User::factory()->create();
    $circle->members()->attach($partner);

    Sanctum::actingAs($creator);

    $this->postJson("/api/persons/{$child->id}/parents", [
        'username' => $partner->username,
    ])
        ->assertOk()
        ->assertJsonPath('data.parents.1.id', $partner->id);

    // The co-parent can now manage the child...
    Sanctum::actingAs($partner);

    $this->putJson("/api/persons/{$child->id}", ['name' => 'Lotje'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Lotje');

    // ...including attaching it to their own circle.
    $partnerCircle = Circle::factory()->for($partner)->create();

    $this->postJson("/api/persons/{$child->id}/circles/{$partnerCircle->id}")
        ->assertOk();
});

it('rejects co-parents who do not share a circle with the child', function () {
    $creator = User::factory()->create();
    [, $child] = makeChildWithCircle($creator);

    $stranger = User::factory()->create();

    Sanctum::actingAs($creator);

    $this->postJson("/api/persons/{$child->id}/parents", [
        'username' => $stranger->username,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('username');
});

it('forbids non-parents from managing parents', function () {
    $creator = User::factory()->create();
    [$circle, $child] = makeChildWithCircle($creator);

    $member = User::factory()->create();
    $circle->members()->attach($member);

    Sanctum::actingAs($member);

    $this->postJson("/api/persons/{$child->id}/parents", [
        'username' => $member->username,
    ])->assertForbidden();
});

it('revokes management when a co-parent is removed', function () {
    $creator = User::factory()->create();
    [$circle, $child] = makeChildWithCircle($creator);

    $partner = User::factory()->create();
    $circle->members()->attach($partner);
    $child->parents()->attach($partner->id);

    Sanctum::actingAs($creator);

    $this->deleteJson("/api/persons/{$child->id}/parents/{$partner->id}")
        ->assertOk();

    Sanctum::actingAs($partner);

    $this->putJson("/api/persons/{$child->id}", ['name' => 'Nee'])
        ->assertForbidden();
});

it('keeps the creator in charge even when removed from the parent list', function () {
    $creator = User::factory()->create();
    [, $child] = makeChildWithCircle($creator);

    $child->parents()->detach($creator->id);

    Sanctum::actingAs($creator);

    $this->putJson("/api/persons/{$child->id}", ['name' => 'Nog steeds'])
        ->assertOk();
});

it('forbids circle owners who are not parents from updating a child', function () {
    $creator = User::factory()->create();
    [, $child] = makeChildWithCircle($creator);

    // The child also sits in another family's circle (e.g. via a co-parent).
    $otherOwner = User::factory()->create();
    $otherCircle = Circle::factory()->for($otherOwner)->create();
    $child->circles()->attach($otherCircle);

    Sanctum::actingAs($otherOwner);

    $this->putJson("/api/persons/{$child->id}", ['name' => 'Niet van jou'])
        ->assertForbidden();

    // But they can still remove the child from their own circle.
    $this->deleteJson("/api/persons/{$child->id}/circles/{$otherCircle->id}")
        ->assertOk();
});
