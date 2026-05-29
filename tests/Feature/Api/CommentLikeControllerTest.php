<?php

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLiked;
use Illuminate\Support\Facades\Notification;

it('can like a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();
    // Liker en reactie-auteur delen dezelfde circle, anders is de reactie
    // onzichtbaar voor de liker en mag deze niet geliket worden.
    shareCircle($comment->post, $user, $comment->user);

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
    shareCircle($comment->post, $user, $comment->user);

    Like::factory()->for($comment, 'likeable')->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertCreated()
        ->assertJsonPath('likes_count', 1);
});

it('can unlike a comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();
    shareCircle($comment->post, $user);

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
    shareCircle($comment->post, $user);

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

it('cannot like own comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertForbidden();

    $this->assertDatabaseMissing('likes', [
        'likeable_id' => $comment->id,
        'likeable_type' => Comment::class,
    ]);
});

it('returns not found for non-existent comment', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/comments/99999/like')
        ->assertNotFound();
});

it('cannot like a comment from an author in a different circle', function () {
    Notification::fake();

    $post = Post::factory()->create();
    $author = User::factory()->create();
    $liker = User::factory()->create();

    // Post gedeeld in twee losse circles: auteur in A, liker in B. De liker kan
    // de post zien (circle B) maar deelt geen circle met de auteur, dus de
    // reactie hoort onzichtbaar te zijn.
    shareCircle($post, $author);
    shareCircle($post, $liker);

    $comment = Comment::factory()->create(['post_id' => $post->id, 'user_id' => $author->id]);

    $this->actingAs($liker)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertNotFound();

    $this->assertDatabaseMissing('likes', [
        'likeable_id' => $comment->id,
        'likeable_type' => Comment::class,
    ]);

    Notification::assertNotSentTo($author, CommentLiked::class);
});
