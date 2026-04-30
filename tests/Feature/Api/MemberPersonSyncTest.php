<?php

use App\Enums\InvitationStatus;
use App\Models\Circle;
use App\Models\CircleInvitation;
use App\Models\Person;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewCirclePost;
use App\Notifications\PostTagged;
use App\Services\MemberPersonSyncer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

describe('circle creation', function () {
    it('auto-creates a member-Person for the owner', function () {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/circles', ['name' => 'Family'])
            ->assertCreated();

        $circle = Circle::where('user_id', $owner->id)->firstOrFail();

        $person = Person::where('user_id', $owner->id)->firstOrFail();

        expect($person->circles()->whereKey($circle->id)->exists())->toBeTrue();
    });
});

describe('invitation acceptance', function () {
    it('attaches the new member as a Person on the circle', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $invitation = CircleInvitation::factory()->create([
            'circle_id' => $circle->id,
            'user_id' => $invitee->id,
            'inviter_id' => $owner->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($invitee)
            ->postJson("/api/circle-invitations/{$invitation->id}/accept")
            ->assertOk();

        $person = Person::where('user_id', $invitee->id)->firstOrFail();
        expect($person->circles()->whereKey($circle->id)->exists())->toBeTrue();
    });

    it('reuses an existing user-linked Person when the user joins another circle', function () {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();

        $syncer = app(MemberPersonSyncer::class);
        $syncer->attach($circleA, $invitee);

        $invitation = CircleInvitation::factory()->create([
            'circle_id' => $circleB->id,
            'user_id' => $invitee->id,
            'inviter_id' => $owner->id,
            'status' => InvitationStatus::Pending,
        ]);

        $this->actingAs($invitee)
            ->postJson("/api/circle-invitations/{$invitation->id}/accept")
            ->assertOk();

        expect(Person::where('user_id', $invitee->id)->count())->toBe(1);
        $person = Person::where('user_id', $invitee->id)->first();
        expect($person->circles()->pluck('circles.id')->all())
            ->toContain($circleA->id, $circleB->id);
    });
});

describe('member removal', function () {
    it('detaches the member-Person from the circle but keeps the Person', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($member);

        $person = app(MemberPersonSyncer::class)->attach($circle, $member);

        $this->actingAs($owner)
            ->deleteJson("/api/circles/{$circle->id}/members/{$member->id}")
            ->assertNoContent();

        expect(Person::find($person->id))->not->toBeNull();
        expect($person->fresh()->circles()->whereKey($circle->id)->exists())->toBeFalse();
    });

    it('keeps the member-Person attached to other circles when removed from one', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();
        $circleA->members()->attach($member);
        $circleB->members()->attach($member);

        $syncer = app(MemberPersonSyncer::class);
        $person = $syncer->attach($circleA, $member);
        $syncer->attach($circleB, $member);

        $this->actingAs($owner)
            ->deleteJson("/api/circles/{$circleA->id}/members/{$member->id}")
            ->assertNoContent();

        $remaining = $person->fresh()->circles()->pluck('circles.id')->all();
        expect($remaining)->toBe([$circleB->id]);
    });
});

describe('person index', function () {
    it('returns auto-synced member persons alongside manual ones', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($member);

        $syncer = app(MemberPersonSyncer::class);
        $syncer->attach($circle, $owner);
        $syncer->attach($circle, $member);

        $manual = Person::factory()->for($owner, 'creator')->create(['name' => 'Aunt Maria']);
        $manual->circles()->attach($circle);

        $response = $this->actingAs($owner)
            ->getJson("/api/persons?circle_id={$circle->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $userIds = collect($response->json('data'))->pluck('user_id')->all();

        expect($ids)->toHaveCount(3);
        expect($userIds)->toContain($owner->id, $member->id, null);
    });
});

describe('manual operations on member persons', function () {
    it('blocks attaching a member-Person to another circle manually', function () {
        $owner = User::factory()->create();
        $circleA = Circle::factory()->for($owner)->create();
        $circleB = Circle::factory()->for($owner)->create();

        $person = app(MemberPersonSyncer::class)->attach($circleA, $owner);

        $this->actingAs($owner)
            ->postJson("/api/persons/{$person->id}/circles/{$circleB->id}")
            ->assertStatus(422);
    });

    it('blocks detaching a member-Person manually', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $person = app(MemberPersonSyncer::class)->attach($circle, $owner);

        $this->actingAs($owner)
            ->deleteJson("/api/persons/{$person->id}/circles/{$circle->id}")
            ->assertStatus(422);
    });

    it('blocks deleting a member-Person', function () {
        $owner = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $person = app(MemberPersonSyncer::class)->attach($circle, $owner);

        $this->actingAs($owner)
            ->deleteJson("/api/persons/{$person->id}")
            ->assertStatus(422);

        expect(Person::find($person->id))->not->toBeNull();
    });

    it('blocks changing the linked user on a member-Person', function () {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();

        $person = app(MemberPersonSyncer::class)->attach($circle, $owner);

        $this->actingAs($owner)
            ->putJson("/api/persons/{$person->id}", ['user_id' => $other->id])
            ->assertStatus(422);
    });
});

describe('post tagging notification', function () {
    beforeEach(function () {
        Storage::fake();
        Notification::fake();
    });

    it('sends PostTagged to a tagged member and skips them in NewCirclePost', function () {
        $poster = User::factory()->create();
        $tagged = User::factory()->create();
        $bystander = User::factory()->create();
        $circle = Circle::factory()->for($poster)->create();
        $circle->members()->attach([$tagged->id, $bystander->id]);

        $syncer = app(MemberPersonSyncer::class);
        $syncer->attach($circle, $poster);
        $taggedPerson = $syncer->attach($circle, $tagged);
        $syncer->attach($circle, $bystander);

        $this->actingAs($poster)
            ->postJson('/api/posts', [
                'media' => UploadedFile::fake()->image('photo.jpg'),
                'circle_ids' => [$circle->id],
                'person_ids' => [$taggedPerson->id],
            ])
            ->assertCreated();

        Notification::assertSentTo($tagged, PostTagged::class);
        Notification::assertNotSentTo($tagged, NewCirclePost::class);
        Notification::assertSentTo($bystander, NewCirclePost::class);
        Notification::assertNotSentTo($bystander, PostTagged::class);
    });

    it('does not notify the poster even if they tag themselves', function () {
        $poster = User::factory()->create();
        $circle = Circle::factory()->for($poster)->create();
        $posterPerson = app(MemberPersonSyncer::class)->attach($circle, $poster);

        $this->actingAs($poster)
            ->postJson('/api/posts', [
                'media' => UploadedFile::fake()->image('photo.jpg'),
                'circle_ids' => [$circle->id],
                'person_ids' => [$posterPerson->id],
            ])
            ->assertCreated();

        Notification::assertNothingSentTo($poster);
    });

    it('sends PostTagged on update for newly added tags only', function () {
        $poster = User::factory()->create();
        $alreadyTagged = User::factory()->create();
        $newlyTagged = User::factory()->create();
        $circle = Circle::factory()->for($poster)->create();
        $circle->members()->attach([$alreadyTagged->id, $newlyTagged->id]);

        $syncer = app(MemberPersonSyncer::class);
        $syncer->attach($circle, $poster);
        $alreadyPerson = $syncer->attach($circle, $alreadyTagged);
        $newPerson = $syncer->attach($circle, $newlyTagged);

        $post = Post::factory()->for($poster)->create();
        $post->circles()->attach($circle);
        $post->syncPersons([$alreadyPerson->id]);

        Notification::fake();

        $this->actingAs($poster)
            ->putJson("/api/posts/{$post->id}", [
                'person_ids' => [$alreadyPerson->id, $newPerson->id],
            ])
            ->assertOk();

        Notification::assertSentTo($newlyTagged, PostTagged::class);
        Notification::assertNotSentTo($alreadyTagged, PostTagged::class);
    });
});
