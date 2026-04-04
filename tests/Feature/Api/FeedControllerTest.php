<?php

use App\Models\Circle;
use App\Models\Like;
use App\Models\Post;
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
