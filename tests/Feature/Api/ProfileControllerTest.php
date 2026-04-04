<?php

use App\Models\Post;
use App\Models\User;

it('can show a user profile', function () {
    $user = User::factory()->create();
    Post::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/profiles/{$user->username}")
        ->assertSuccessful()
        ->assertJsonPath('data.username', $user->username)
        ->assertJsonPath('data.posts_count', 3)
        ->assertJsonStructure([
            'data' => ['id', 'name', 'username', 'avatar', 'bio', 'created_at', 'posts_count'],
        ])
        ->assertJsonMissing(['email']);
});

it('returns not found for non-existent username', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/profiles/nonexistent')
        ->assertNotFound();
});

it('requires authentication to view a profile', function () {
    $user = User::factory()->create();

    $this->getJson("/api/profiles/{$user->username}")
        ->assertUnauthorized();
});

it('can list profile posts', function () {
    $user = User::factory()->create();
    Post::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertSuccessful()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'media_url', 'media_type', 'caption', 'likes_count', 'comments_count'],
            ],
            'links',
            'meta',
        ]);
});

it('paginates profile posts', function () {
    $user = User::factory()->create();
    Post::factory()->count(15)->create(['user_id' => $user->id]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/profiles/{$user->username}/posts")
        ->assertSuccessful()
        ->assertJsonCount(10, 'data');
});

it('requires authentication to view profile posts', function () {
    $user = User::factory()->create();

    $this->getJson("/api/profiles/{$user->username}/posts")
        ->assertUnauthorized();
});
