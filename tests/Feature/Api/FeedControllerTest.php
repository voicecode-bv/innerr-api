<?php

use App\Models\Circle;
use App\Models\Like;
use App\Models\Person;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

it('returns posts from circles the user owns', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create();
    $post->circles()->attach($circle);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($post->id);
});

it('returns posts from circles the user is a member of', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create();
    $circle->members()->attach($user);
    $post = Post::factory()->create();
    $post->circles()->attach($circle);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($post->id);
});

it('returns own posts in the feed', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($post->id);
});

it('does not return posts from circles the user has no access to', function () {
    $user = User::factory()->create();
    $otherCircle = Circle::factory()->create();
    $post = Post::factory()->create();
    $post->circles()->attach($otherCircle);

    $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('does not return duplicate posts shared with multiple accessible circles', function () {
    $user = User::factory()->create();
    $circle1 = Circle::factory()->create(['user_id' => $user->id]);
    $circle2 = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create();
    $post->circles()->attach([$circle1->id, $circle2->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data'))->toHaveCount(1);
});

it('returns paginated feed', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $posts = Post::factory()->count(15)->create();
    foreach ($posts as $post) {
        $post->circles()->attach($circle);
    }

    $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure(['data', 'links', 'meta']);

    $this->actingAs($user)
        ->getJson('/api/feed?page=2')
        ->assertSuccessful()
        ->assertJsonCount(5, 'data');
});

it('returns posts in newest-first order', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $oldest = Post::factory()->create(['created_at' => now()->subDay()]);
    $newest = Post::factory()->create(['created_at' => now()]);
    $oldest->circles()->attach($circle);
    $newest->circles()->attach($circle);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids[0])->toBe($newest->id)
        ->and($ids[1])->toBe($oldest->id);
});

it('returns is_liked true when user has liked the post', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create();
    $post->circles()->attach($circle);
    Like::factory()->for($post, 'likeable')->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.is_liked', true);
});

it('returns is_liked false when user has not liked the post', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create();
    $post->circles()->attach($circle);

    $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.is_liked', false);
});

it('includes circles on own posts in the feed', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    $post->circles()->attach($circle);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data.0.circles'))->toHaveCount(1)
        ->and($response->json('data.0.circles.0.id'))->toBe($circle->id)
        ->and($response->json('data.0.circles.0.name'))->toBe($circle->name);
});

it('does not include circles on posts from other users', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create();
    $post = Post::factory()->create();
    $post->circles()->attach($circle);

    $response = $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful();

    expect($response->json('data.0.circles'))->toBeNull();
});

it('returns empty data when no posts exist', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('marks own posts as downloadable in the feed', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->for($user)->create(['members_can_download' => false]);
    $post = Post::factory()->create(['user_id' => $user->id]);
    $post->circles()->attach($circle);

    $this->actingAs($user)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.is_downloadable', true);
});

it('marks viewer posts as downloadable when any shared circle allows download', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $allowsDownload = Circle::factory()->for($owner)->create(['members_can_download' => true]);
    $blocksDownload = Circle::factory()->for($owner)->create(['members_can_download' => false]);
    $allowsDownload->members()->attach($viewer);
    $blocksDownload->members()->attach($viewer);

    $post = Post::factory()->create(['user_id' => $owner->id]);
    $post->circles()->attach([$allowsDownload->id, $blocksDownload->id]);

    $this->actingAs($viewer)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.is_downloadable', true);
});

it('does not mark viewer posts as downloadable when no shared circle allows download', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $circle = Circle::factory()->for($owner)->create(['members_can_download' => false]);
    $circle->members()->attach($viewer);

    $post = Post::factory()->create(['user_id' => $owner->id]);
    $post->circles()->attach($circle);

    $this->actingAs($viewer)
        ->getJson('/api/feed')
        ->assertSuccessful()
        ->assertJsonPath('data.0.is_downloadable', false);
});

it('requires authentication to view feed', function () {
    $this->getJson('/api/feed')
        ->assertUnauthorized();
});

describe('person filters', function () {
    it('filters posts by person_ids', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();

        $oma = Person::factory()->for($user, 'creator')->create();
        $oma->circles()->attach($circle);

        $matching = Post::factory()->for($user)->create();
        $matching->circles()->attach($circle);
        $matching->syncPersons([$oma->id]);

        $other = Post::factory()->for($user)->create();
        $other->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?person_ids[]='.$oma->id)
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($matching->id);
    });

    it('returns nothing when filtering by a person from another user\'s circle', function () {
        $stranger = User::factory()->create();
        $strangerCircle = Circle::factory()->for($stranger)->create();
        $strangerPerson = Person::factory()->for($stranger, 'creator')->create();
        $strangerPerson->circles()->attach($strangerCircle);
        $strangerPost = Post::factory()->for($stranger)->create();
        $strangerPost->circles()->attach($strangerCircle);
        $strangerPost->syncPersons([$strangerPerson->id]);

        $user = User::factory()->create();
        $ownCircle = Circle::factory()->for($user)->create();
        $ownPost = Post::factory()->for($user)->create();
        $ownPost->circles()->attach($ownCircle);

        $this->actingAs($user)
            ->getJson('/api/feed?person_ids[]='.$strangerPerson->id)
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    });

    it('exposes persons attached to posts to fellow circle members', function () {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Circle::factory()->for($owner)->create();
        $circle->members()->attach($member);

        $person = Person::factory()->for($owner, 'creator')->create(['name' => 'Oma Marie']);
        $person->circles()->attach($circle);

        $post = Post::factory()->for($owner)->create();
        $post->circles()->attach($circle);
        $post->syncPersons([$person->id]);

        $response = $this->actingAs($member)
            ->getJson('/api/feed')
            ->assertSuccessful();

        expect($response->json('data.0.persons'))->toHaveCount(1)
            ->and($response->json('data.0.persons.0.name'))->toBe('Oma Marie');
    });
});

describe('tag filters', function () {
    it('filters posts by tag_ids for the authenticated user', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();

        $matching = Post::factory()->for($user)->create();
        $matching->circles()->attach($circle);
        $matching->syncTags([$tag->id]);

        $other = Post::factory()->for($user)->create();
        $other->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?tag_ids[]='.$tag->id)
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($matching->id);
    });

    it('returns nothing when filtering by another user\'s tag', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();
        $post = Post::factory()->for($user)->create();
        $post->circles()->attach($circle);

        $foreignTag = Tag::factory()->create(); // belongs to a different user

        $this->actingAs($user)
            ->getJson('/api/feed?tag_ids[]='.$foreignTag->id)
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    });
});

describe('circle feed', function () {
    it('requires authentication', function () {
        $circle = Circle::factory()->create();

        $this->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertUnauthorized();
    });

    it('returns 404 for an unknown circle', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/circles/99999/feed')
            ->assertNotFound();
    });

    it('returns 403 when the user is not a member or owner', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertForbidden();
    });

    it('returns posts in the circle when the user owns it', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $user->id]);
        $post = Post::factory()->create();
        $post->circles()->attach($circle);

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id);
    });

    it('returns posts in the circle when the user is a member', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create();
        $circle->members()->attach($user);
        $post = Post::factory()->create();
        $post->circles()->attach($circle);

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $post->id);
    });

    it('excludes posts that are not in the circle', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $user->id]);
        $otherCircle = Circle::factory()->create(['user_id' => $user->id]);
        $post = Post::factory()->create();
        $post->circles()->attach($otherCircle);

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    });

    it('returns posts newest-first', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $user->id]);
        $oldest = Post::factory()->create(['created_at' => now()->subDay()]);
        $newest = Post::factory()->create(['created_at' => now()]);
        $oldest->circles()->attach($circle);
        $newest->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids[0])->toBe($newest->id)
            ->and($ids[1])->toBe($oldest->id);
    });

    it('paginates with 21 per page', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $user->id]);
        $posts = Post::factory()->count(23)->create();
        foreach ($posts as $post) {
            $post->circles()->attach($circle);
        }

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful()
            ->assertJsonCount(21, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed?page=2')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    });

    it('reflects is_liked for the authenticated user', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $user->id]);
        $post = Post::factory()->create();
        $post->circles()->attach($circle);
        Like::factory()->for($post, 'likeable')->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->getJson('/api/circles/'.$circle->id.'/feed')
            ->assertSuccessful()
            ->assertJsonPath('data.0.is_liked', true);
    });
});

describe('circle filters', function () {
    it('filters posts by a single circle_id', function () {
        $user = User::factory()->create();
        $circleA = Circle::factory()->for($user)->create();
        $circleB = Circle::factory()->for($user)->create();

        $inA = Post::factory()->for($user)->create();
        $inA->circles()->attach($circleA);

        $inB = Post::factory()->for($user)->create();
        $inB->circles()->attach($circleB);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?circle_ids[]='.$circleA->id)
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($inA->id);
    });

    it('filters posts by multiple circle_ids', function () {
        $user = User::factory()->create();
        $circleA = Circle::factory()->for($user)->create();
        $circleB = Circle::factory()->for($user)->create();
        $circleC = Circle::factory()->for($user)->create();

        $inA = Post::factory()->for($user)->create();
        $inA->circles()->attach($circleA);
        $inB = Post::factory()->for($user)->create();
        $inB->circles()->attach($circleB);
        $inC = Post::factory()->for($user)->create();
        $inC->circles()->attach($circleC);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?circle_ids[]='.$circleA->id.'&circle_ids[]='.$circleB->id)
            ->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toHaveCount(2)
            ->and($ids)->toContain($inA->id)
            ->and($ids)->toContain($inB->id);
    });

    it('ignores circle_ids the user cannot access', function () {
        $user = User::factory()->create();
        $ownCircle = Circle::factory()->for($user)->create();
        $ownPost = Post::factory()->for($user)->create();
        $ownPost->circles()->attach($ownCircle);

        $strangerCircle = Circle::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/feed?circle_ids[]='.$strangerCircle->id)
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    });
});

describe('date filters', function () {
    it('filters posts by taken_at within the range', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();

        $inRange = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $inRange->circles()->attach($circle);
        $before = Post::factory()->for($user)->create(['taken_at' => '2024-01-01 12:00:00']);
        $before->circles()->attach($circle);
        $after = Post::factory()->for($user)->create(['taken_at' => '2024-12-31 12:00:00']);
        $after->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?date_from=2024-06-01&date_to=2024-06-30')
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($inRange->id);
    });

    it('filters with only date_from', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();

        $recent = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $recent->circles()->attach($circle);
        $old = Post::factory()->for($user)->create(['taken_at' => '2020-01-01 12:00:00']);
        $old->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?date_from=2024-01-01')
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($recent->id);
    });

    it('filters with only date_to', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();

        $recent = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $recent->circles()->attach($circle);
        $old = Post::factory()->for($user)->create(['taken_at' => '2020-01-01 12:00:00']);
        $old->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?date_to=2021-01-01')
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($old->id);
    });

    it('excludes posts without a taken_at when a date filter is active', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();

        $dated = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $dated->circles()->attach($circle);
        $undated = Post::factory()->for($user)->create(['taken_at' => null]);
        $undated->circles()->attach($circle);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?date_from=2024-01-01&date_to=2024-12-31')
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($dated->id);
    });

    it('rejects date_to before date_from', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/feed?date_from=2024-12-31&date_to=2024-01-01')
            ->assertStatus(422);
    });
});

describe('combined filters', function () {
    it('combines person, circle and date filters with AND', function () {
        $user = User::factory()->create();
        $circle = Circle::factory()->for($user)->create();
        $person = Person::factory()->for($user, 'creator')->create();
        $person->circles()->attach($circle);

        $match = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $match->circles()->attach($circle);
        $match->syncPersons([$person->id]);

        $noPerson = Post::factory()->for($user)->create(['taken_at' => '2024-06-15 12:00:00']);
        $noPerson->circles()->attach($circle);

        $outOfRange = Post::factory()->for($user)->create(['taken_at' => '2020-06-15 12:00:00']);
        $outOfRange->circles()->attach($circle);
        $outOfRange->syncPersons([$person->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/feed?circle_ids[]='.$circle->id.'&person_ids[]='.$person->id.'&date_from=2024-01-01&date_to=2024-12-31')
            ->assertSuccessful();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.id'))->toBe($match->id);
    });
});

describe('sorting', function () {
    it('sorts by taken_at when requested, ignoring upload order', function () {
        $user = User::factory()->create();

        // Uploaded just now but captured two years ago.
        $uploadedLastTakenFirst = Post::factory()->for($user)->create([
            'created_at' => now(),
            'taken_at' => now()->subYears(2),
        ]);

        // Uploaded yesterday but captured today.
        $uploadedFirstTakenLast = Post::factory()->for($user)->create([
            'created_at' => now()->subDay(),
            'taken_at' => now(),
        ]);

        $ids = collect(
            $this->actingAs($user)
                ->getJson('/api/feed?sort=taken_at')
                ->assertSuccessful()
                ->json('data')
        )->pluck('id')->all();

        expect($ids[0])->toBe($uploadedFirstTakenLast->id)
            ->and($ids[1])->toBe($uploadedLastTakenFirst->id);
    });

    it('falls back to created_at when a post has no taken_at', function () {
        $user = User::factory()->create();

        $withTakenAt = Post::factory()->for($user)->create([
            'created_at' => now()->subYear(),
            'taken_at' => now()->subYear(),
        ]);

        // No EXIF capture date, uploaded just now: should rank first.
        $withoutTakenAt = Post::factory()->for($user)->create([
            'created_at' => now(),
            'taken_at' => null,
        ]);

        $ids = collect(
            $this->actingAs($user)
                ->getJson('/api/feed?sort=taken_at')
                ->assertSuccessful()
                ->json('data')
        )->pluck('id')->all();

        expect($ids[0])->toBe($withoutTakenAt->id)
            ->and($ids[1])->toBe($withTakenAt->id);
    });

    it('rejects an unknown sort value', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/feed?sort=bogus')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    });
});
