<?php

use App\Models\Comment;
use App\Models\Like;
use App\Models\User;

it('can like a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertCreated()
        ->assertJsonPath('liked', true)
        ->assertJsonPath('likes_count', 1);

    $this->assertDatabaseHas('likes', [
        'user_id' => $user->id,
        'likeable_id' => $comment->id,
        'likeable_type' => Comment::class,
    ]);
});

it('liking a comment is idempotent', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    Like::factory()->for($comment, 'likeable')->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertCreated()
        ->assertJsonPath('likes_count', 1);
});

it('can unlike a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    Like::factory()->for($comment, 'likeable')->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/comments/{$comment->id}/like")
        ->assertSuccessful()
        ->assertJsonPath('liked', false)
        ->assertJsonPath('likes_count', 0);

    $this->assertDatabaseMissing('likes', [
        'user_id' => $user->id,
        'likeable_id' => $comment->id,
        'likeable_type' => Comment::class,
    ]);
});

it('unliking a comment that is not liked returns zero', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    $this->actingAs($user)
        ->deleteJson("/api/comments/{$comment->id}/like")
        ->assertSuccessful()
        ->assertJsonPath('liked', false)
        ->assertJsonPath('likes_count', 0);
});

it('requires authentication to like a comment', function () {
    $comment = Comment::factory()->create();

    $this->postJson("/api/comments/{$comment->id}/like")
        ->assertUnauthorized();
});

it('requires authentication to unlike a comment', function () {
    $comment = Comment::factory()->create();

    $this->deleteJson("/api/comments/{$comment->id}/like")
        ->assertUnauthorized();
});

it('returns not found for non-existent comment', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/comments/99999/like')
        ->assertNotFound();
});
