<?php

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function makeCircleWithChild(User $owner): array
{
    $circle = Circle::factory()->for($owner)->create();
    $person = Person::factory()->create(['is_child' => true]);
    $person->circles()->attach($circle);

    return [$circle, $person];
}

it('returns an empty filter by default', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/child-filter')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('stores and returns the selected person ids', function () {
    $user = User::factory()->create();
    [, $person] = makeCircleWithChild($user);

    Sanctum::actingAs($user);

    $this->putJson('/api/child-filter', ['person_ids' => [$person->id]])
        ->assertOk()
        ->assertExactJson(['data' => [$person->id]]);

    $this->getJson('/api/child-filter')
        ->assertOk()
        ->assertExactJson(['data' => [$person->id]]);
});

it('clears the filter with an empty array', function () {
    $user = User::factory()->create();
    [, $person] = makeCircleWithChild($user);
    $user->update(['child_filter_ids' => [$person->id]]);

    Sanctum::actingAs($user);

    $this->putJson('/api/child-filter', ['person_ids' => []])
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('silently drops persons outside the user view', function () {
    $user = User::factory()->create();
    [, $ownPerson] = makeCircleWithChild($user);
    [, $foreignPerson] = makeCircleWithChild(User::factory()->create());

    Sanctum::actingAs($user);

    $this->putJson('/api/child-filter', [
        'person_ids' => [$ownPerson->id, $foreignPerson->id],
    ])
        ->assertOk()
        ->assertExactJson(['data' => [$ownPerson->id]]);
});

it('accepts persons from circles the user is a member of', function () {
    $owner = User::factory()->create();
    [$circle, $person] = makeCircleWithChild($owner);

    $member = User::factory()->create();
    $circle->members()->attach($member);

    Sanctum::actingAs($member);

    $this->putJson('/api/child-filter', ['person_ids' => [$person->id]])
        ->assertOk()
        ->assertExactJson(['data' => [$person->id]]);
});

it('drops persons from membership circles with a hidden member list', function () {
    $owner = User::factory()->create();
    [$circle, $person] = makeCircleWithChild($owner);
    $circle->update(['members_can_view_members' => false]);

    $member = User::factory()->create();
    $circle->members()->attach($member);

    Sanctum::actingAs($member);

    $this->putJson('/api/child-filter', ['person_ids' => [$person->id]])
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('validates the person_ids payload', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->putJson('/api/child-filter', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('person_ids');

    $this->putJson('/api/child-filter', ['person_ids' => ['not-a-uuid']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('person_ids.0');
});
