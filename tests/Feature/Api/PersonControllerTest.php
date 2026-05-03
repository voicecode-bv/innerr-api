<?php

use App\Models\Circle;
use App\Models\Person;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('index', function () {
    it('returns persons the user created', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        Person::factory()->create(); // unrelated, by another creator

        $this->actingAs($owner)
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $person->id);
    });

    it('does not return persons created by others, even in shared circles', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($member);

        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($member)
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('does not leak persons from circles the user has no access to', function () {
        $stranger = User::factory()->create();
        $circle = Circle::factory()->create();
        $person = Person::factory()->create();
        $person->circles()->attach($circle);

        $this->actingAs($stranger)
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('filters persons by circle_id', function () {
        $owner = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();

        $personA = Person::factory()->for($owner, 'creator')->create();
        $personA->circles()->attach($circleA);

        $personB = Person::factory()->for($owner, 'creator')->create();
        $personB->circles()->attach($circleB);

        $this->actingAs($owner)
            ->getJson('/api/persons?circle_id='.$circleA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $personA->id);
    });

    it('filtering by circle_id still hides persons created by others', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($member);

        $ownersPerson = Person::factory()->for($owner, 'creator')->create();
        $ownersPerson->circles()->attach($circle);

        $this->actingAs($member)
            ->getJson('/api/persons?circle_id='.$circle->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('returns 403 when filtering by an inaccessible circle', function () {
        $stranger = User::factory()->create();
        $circle = Circle::factory()->create();

        $this->actingAs($stranger)
            ->getJson('/api/persons?circle_id='.$circle->id)
            ->assertForbidden();
    });

    it('returns persons the user created that are not yet in any circle', function () {
        $owner = User::factory()->create();
        $person = Person::factory()->for($owner, 'creator')->create();

        $this->actingAs($owner)
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $person->id);
    });

    it('does not leak circle-less persons created by other users', function () {
        $creator = User::factory()->create();
        Person::factory()->for($creator, 'creator')->create();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/persons')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('requires authentication to list persons', function () {
        $this->getJson('/api/persons')->assertUnauthorized();
    });
});

describe('taggable', function () {
    it('returns every person across the given circles, regardless of creator', function () {
        $author = User::factory()->create();
        $member = User::factory()->create();
        $circleA = Circle::factory()->for($author)->create();
        $circleA->members()->attach($member);
        $circleB = Circle::factory()->for($author)->create();

        $authorsPerson = Person::factory()->for($author, 'creator')->create();
        $authorsPerson->circles()->attach($circleA);

        $membersPerson = Person::factory()->for($member, 'creator')->create();
        $membersPerson->circles()->attach($circleA);

        $otherCirclePerson = Person::factory()->for($author, 'creator')->create();
        $otherCirclePerson->circles()->attach($circleB);

        $unattachedPerson = Person::factory()->for($author, 'creator')->create();

        $response = $this->actingAs($author)
            ->getJson('/api/persons/taggable?circle_ids[]='.$circleA->id)
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        expect($ids)->toEqualCanonicalizing([$authorsPerson->id, $membersPerson->id]);
    });

    it('forbids requesting persons from a circle the user is not part of', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->getJson('/api/persons/taggable?circle_ids[]='.$circle->id)
            ->assertForbidden();
    });

    it('forbids requesting when only some circles are accessible', function () {
        $author = User::factory()->create();
        $accessible = Circle::factory()->for($author)->create();
        $foreign = Circle::factory()->create();

        $this->actingAs($author)
            ->getJson('/api/persons/taggable?circle_ids[]='.$accessible->id.'&circle_ids[]='.$foreign->id)
            ->assertForbidden();
    });

    it('requires at least one circle_id', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/persons/taggable')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle_ids');
    });

    it('requires authentication', function () {
        $this->getJson('/api/persons/taggable?circle_ids[]=1')->assertUnauthorized();
    });
});

describe('store', function () {
    it('lets the circle owner create a person in their own circle', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Oma Marie',
                'circle_ids' => [$circle->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Oma Marie')
            ->assertJsonPath('data.created_by_user_id', $owner->id)
            ->assertJsonPath('data.circle_ids', [$circle->id]);

        expect(Person::where('name', 'Oma Marie')->first())
            ->not->toBeNull()
            ->created_by_user_id->toBe($owner->id);
    });

    it('lets a member create a person when members_can_invite is true', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
        $circle->members()->attach($member);

        $this->actingAs($member)
            ->postJson('/api/persons', [
                'name' => 'Opa Jan',
                'circle_ids' => [$circle->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.created_by_user_id', $member->id);
    });

    it('rejects a member when members_can_invite is false', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create(['members_can_invite' => false]);
        $circle->members()->attach($member);

        $this->actingAs($member)
            ->postJson('/api/persons', [
                'name' => 'Sneaky',
                'circle_ids' => [$circle->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle_ids.0');
    });

    it('rejects a non-member trying to create a person in a circle', function () {
        $stranger = User::factory()->create();
        $circle = Circle::factory()->create();

        $this->actingAs($stranger)
            ->postJson('/api/persons', [
                'name' => 'Mole',
                'circle_ids' => [$circle->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('circle_ids.0');
    });

    it('attaches the person to every given circle', function () {
        $owner = User::factory()->create();
        $a = Circle::factory()->for($owner)->create();
        $b = Circle::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Tante Truus',
                'circle_ids' => [$a->id, $b->id],
            ])
            ->assertCreated();

        $person = Person::firstWhere('name', 'Tante Truus');
        expect($person->circles()->pluck('circles.id')->all())->toEqualCanonicalizing([$a->id, $b->id]);
    });

    it('accepts a birthdate', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Sarah',
                'birthdate' => '1990-05-12',
                'circle_ids' => [$circle->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.birthdate', '1990-05-12');
    });

    it('rejects a future birthdate', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Future',
                'birthdate' => now()->addYear()->toDateString(),
                'circle_ids' => [$circle->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birthdate');
    });

    it('lets the person be linked to a user account that is a member of every circle', function () {
        $owner = User::factory()->create();
        $linked = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($linked);

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Kees',
                'user_id' => $linked->id,
                'circle_ids' => [$circle->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $linked->id);
    });

    it('rejects linking a user that is not a member of every circle', function () {
        $owner = User::factory()->create();
        $linked = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();
        $circleA->members()->attach($linked);

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'Kees',
                'user_id' => $linked->id,
                'circle_ids' => [$circleA->id, $circleB->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    });

    it('lets a user without any circles still create a person', function () {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', ['name' => 'Lonely'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Lonely')
            ->assertJsonPath('data.created_by_user_id', $owner->id)
            ->assertJsonPath('data.circle_ids', []);

        $person = Person::firstWhere('name', 'Lonely');
        expect($person->circles()->count())->toBe(0);
    });

    it('treats an empty circle_ids array the same as omitting it', function () {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/persons', [
                'name' => 'StillLonely',
                'circle_ids' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.circle_ids', []);
    });
});

describe('update', function () {
    it('lets the creator update name and birthdate', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create(['name' => 'Old']);
        $person->circles()->attach($circle);

        $this->actingAs($owner)
            ->putJson('/api/persons/'.$person->id, [
                'name' => 'New',
                'birthdate' => '1985-01-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.birthdate', '1985-01-01');
    });

    it('forbids a circle owner from updating a person someone else created', function () {
        $circleOwner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($circleOwner)->create(['members_can_invite' => true]);
        $circle->members()->attach($member);

        $person = Person::factory()->for($member, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($circleOwner)
            ->putJson('/api/persons/'.$person->id, ['name' => 'Renamed'])
            ->assertForbidden();
    });

    it('forbids a circle member from updating someone else\'s person', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
        $circle->members()->attach($other);

        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($other)
            ->putJson('/api/persons/'.$person->id, ['name' => 'Hijacked'])
            ->assertForbidden();
    });

    it('forbids a stranger from updating', function () {
        $person = Person::factory()->create();
        $person->circles()->attach(Circle::factory()->create());

        $this->actingAs(User::factory()->create())
            ->putJson('/api/persons/'.$person->id, ['name' => 'Hijacked'])
            ->assertForbidden();
    });
});

describe('destroy', function () {
    it('lets the creator delete', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($owner)
            ->deleteJson('/api/persons/'.$person->id)
            ->assertNoContent();

        expect(Person::find($person->id))->toBeNull();
    });

    it('forbids a circle owner from deleting a person someone else created', function () {
        $circleOwner = User::factory()->create();
        $creator = User::factory()->create();
        $circle = Circle::factory()->for($circleOwner)->create(['members_can_invite' => true]);
        $circle->members()->attach($creator);

        $person = Person::factory()->for($creator, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($circleOwner)
            ->deleteJson('/api/persons/'.$person->id)
            ->assertForbidden();

        expect(Person::find($person->id))->not->toBeNull();
    });

    it('forbids any non-creator from deleting', function () {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create(['members_can_invite' => true]);
        $circle->members()->attach($creator);
        $circle->members()->attach($member);

        $person = Person::factory()->for($creator, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($member)
            ->deleteJson('/api/persons/'.$person->id)
            ->assertForbidden();
    });

    it('detaches the person from posts when deleted', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);
        $post = Post::factory()->for($owner)->create();
        $post->syncPersons([$person->id]);

        $this->actingAs($owner)
            ->deleteJson('/api/persons/'.$person->id)
            ->assertNoContent();

        expect($post->persons()->count())->toBe(0);
    });
});

describe('avatar', function () {
    it('uploads an avatar for a person', function () {
        Storage::fake('public');

        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($owner)
            ->postJson('/api/persons/'.$person->id.'/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $person->id);

        $person->refresh();
        expect($person->avatar)->not->toBeNull()
            ->and($person->avatar_thumbnail)->not->toBeNull();

        Storage::disk('public')->assertExists($person->avatar_thumbnail);
    });

    it('forbids uploading an avatar to a person not visible to the user', function () {
        Storage::fake('public');

        $person = Person::factory()->create();
        $person->circles()->attach(Circle::factory()->create());

        $this->actingAs(User::factory()->create())
            ->postJson('/api/persons/'.$person->id.'/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ])
            ->assertForbidden();
    });

    it('deletes the avatar files when a person is deleted', function () {
        Storage::fake('public');

        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circle);

        $this->actingAs($owner)
            ->postJson('/api/persons/'.$person->id.'/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ])
            ->assertOk();

        $thumbnail = $person->fresh()->avatar_thumbnail;

        $this->actingAs($owner)
            ->deleteJson('/api/persons/'.$person->id)
            ->assertNoContent();

        Storage::disk('public')->assertMissing($thumbnail);
    });
});

describe('attach/detach circle', function () {
    it('lets the creator attach their person to a circle they manage', function () {
        $owner = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();

        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach($circleA);

        $this->actingAs($owner)
            ->postJson('/api/persons/'.$person->id.'/circles/'.$circleB->id)
            ->assertOk();

        expect($person->fresh()->circles()->pluck('circles.id')->all())
            ->toEqualCanonicalizing([$circleA->id, $circleB->id]);
    });

    it('forbids a non-creator from attaching the person to their own circle', function () {
        $creator = User::factory()->create();
        $otherOwner = User::factory()->create();
        $circleA = Circle::factory()->for($creator)->create();
        $circleB = Circle::factory()->for($otherOwner)->create();

        $person = Person::factory()->for($creator, 'creator')->create();
        $person->circles()->attach($circleA);

        $this->actingAs($otherOwner)
            ->postJson('/api/persons/'.$person->id.'/circles/'.$circleB->id)
            ->assertForbidden();
    });

    it('rejects attaching when the linked user is not a member of the target circle', function () {
        $owner = User::factory()->create();
        $linked = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleA->members()->attach($linked);
        $circleB = Circle::factory()->for($owner)->create();
        // linked is NOT a member of circleB

        $person = Person::factory()->for($owner, 'creator')->create([
            'user_id' => $linked->id,
        ]);
        $person->circles()->attach($circleA);

        $this->actingAs($owner)
            ->postJson('/api/persons/'.$person->id.'/circles/'.$circleB->id)
            ->assertUnprocessable();
    });

    it('lets the creator detach the person from a circle', function () {
        $owner = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();
        $person = Person::factory()->for($owner, 'creator')->create();
        $person->circles()->attach([$circleA->id, $circleB->id]);

        $this->actingAs($owner)
            ->deleteJson('/api/persons/'.$person->id.'/circles/'.$circleB->id)
            ->assertOk();

        expect($person->fresh()->circles()->pluck('circles.id')->all())->toEqual([$circleA->id]);
    });
});
