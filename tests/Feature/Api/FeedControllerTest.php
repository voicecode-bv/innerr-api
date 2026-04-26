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
