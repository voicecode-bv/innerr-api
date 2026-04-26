<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentReplied;
use App\Notifications\PostCommented;
use Illuminate\Support\Facades\Notification;

it('returns paginated top-level comments for a post, newest first, with nested replies', function () {
    $post = Post::factory()->create();

    $oldTopLevel = Comment::factory()->create([
        'post_id' => $post->id,
        'created_at' => now()->subMinutes(10),
    ]);
    $newTopLevel = Comment::factory()->create([
        'post_id' => $post->id,
        'created_at' => now(),
    ]);
    $reply = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_comment_id' => $oldTopLevel->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newTopLevel->id)
        ->assertJsonPath('data.1.id', $oldTopLevel->id)
        ->assertJsonPath('data.1.replies.0.id', $reply->id)
        ->assertJsonStructure([
            'data' => [
                ['id', 'body', 'created_at', 'user' => ['id'], 'replies'],
            ],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('paginates comments at 20 per page', function () {
    $post = Post::factory()->create();
    Comment::factory()->count(25)->create(['post_id' => $post->id]);

    $response = $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments?page=2")
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

it('requires authentication to list comments', function () {
    $post = Post::factory()->create();

    $this->getJson("/api/posts/{$post->id}/comments")
        ->assertUnauthorized();
});

it('returns not found when listing comments for a non-existent post', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/posts/99999/comments')
        ->assertNotFound();
});

it('reflects is_liked on listed comments for the authenticated user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);
    $comment->likes()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', true);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', false);
});

it('can store a comment on a post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Great post!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Great post!')
        ->assertJsonStructure([
            'data' => [
                'id', 'body', 'created_at', 'updated_at',
                'user' => ['id', 'name', 'username', 'avatar'],
            ],
        ]);

    $this->assertDatabaseHas('comments', [
        'user_id' => $user->id,
        'post_id' => $post->id,
        'body' => 'Great post!',
    ]);
});

it('validates comment body is required', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/comments", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('validates comment body max length', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => str_repeat('a', 1001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('body');
});

it('requires authentication to store a comment', function () {
    $post = Post::factory()->create();

    $this->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hello'])
        ->assertUnauthorized();
});

it('throttles comment creation', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();

    $this->actingAs($user);

    foreach (range(1, 30) as $i) {
        $this->postJson("/api/posts/{$post->id}/comments", ['body' => "Comment {$i}"])
            ->assertCreated();
    }

    $this->postJson("/api/posts/{$post->id}/comments", ['body' => 'Too many'])
        ->assertStatus(429);
});

it('returns not found for commenting on non-existent post', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/posts/99999/comments', ['body' => 'Hello'])
        ->assertNotFound();
});

it('can delete own comment', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('post owner can delete any comment on their post', function () {
    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $comment = Comment::factory()->create(['post_id' => $post->id]);

    $this->actingAs($postOwner)
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
});

it('cannot delete another users comment on someone elses post', function () {
    $comment = Comment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson("/api/comments/{$comment->id}")
        ->assertForbidden();
});

it('requires authentication to delete a comment', function () {
    $comment = Comment::factory()->create();

    $this->deleteJson("/api/comments/{$comment->id}")
        ->assertUnauthorized();
});

it('notifies the post owner when a top-level comment is added', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $commenter = User::factory()->create();

    $this->actingAs($commenter)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Hi!'])
        ->assertCreated();

    Notification::assertSentTo($postOwner, PostCommented::class);
});

it('does not notify the post owner when a reply is added to a comment', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $commenter = User::factory()->create();
    $parentComment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $commenter->id,
    ]);

    $replier = User::factory()->create();

    $this->actingAs($replier)
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Reply!',
            'parent_comment_id' => $parentComment->id,
        ])
        ->assertCreated();

    Notification::assertNotSentTo($postOwner, PostCommented::class);
    Notification::assertSentTo($commenter, CommentReplied::class);
});

it('rejects a parent_comment_id from a different post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    $otherPost = Post::factory()->create();
    $otherComment = Comment::factory()->create(['post_id' => $otherPost->id]);

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Cross-post reply attempt',
            'parent_comment_id' => $otherComment->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_comment_id']);

    $this->assertDatabaseMissing('comments', [
        'post_id' => $post->id,
        'parent_comment_id' => $otherComment->id,
    ]);
});

it('returns paginated replies for a comment, oldest first', function () {
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);

    $first = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
        'created_at' => now()->subMinutes(10),
    ]);
    $second = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/comments/{$parent->id}/replies")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJsonStructure([
            'data' => [['id', 'body', 'user' => ['id'], 'replies_count']],
            'links',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('paginates replies at default 10 per page and respects per_page', function () {
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->count(15)->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/comments/{$parent->id}/replies")
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/comments/{$parent->id}/replies?per_page=5")
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.last_page', 3);
});

it('caps per_page at 50 for the replies endpoint', function () {
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->count(60)->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/comments/{$parent->id}/replies?per_page=200")
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonCount(50, 'data');
});

it('reflects is_liked on replies for the authenticated user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);
    $reply = Comment::factory()->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
    ]);
    $reply->likes()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson("/api/comments/{$parent->id}/replies")
        ->assertOk()
        ->assertJsonPath('data.0.is_liked', true)
        ->assertJsonPath('data.0.likes_count', 1);
});

it('returns an empty page when there are no replies', function () {
    $parent = Comment::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/comments/{$parent->id}/replies")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('returns not found for a non-existent comment', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/comments/99999/replies')
        ->assertNotFound();
});

it('requires authentication to list replies', function () {
    $parent = Comment::factory()->create();

    $this->getJson("/api/comments/{$parent->id}/replies")
        ->assertUnauthorized();
});

it('exposes replies_count on top-level comments in the index', function () {
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->count(3)->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.id', $parent->id)
        ->assertJsonPath('data.0.replies_count', 3);
});

it('limits the nested replies on the index payload to 10', function () {
    $post = Post::factory()->create();
    $parent = Comment::factory()->create(['post_id' => $post->id]);
    Comment::factory()->count(15)->create([
        'post_id' => $post->id,
        'parent_comment_id' => $parent->id,
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson("/api/posts/{$post->id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.replies_count', 15)
        ->assertJsonCount(10, 'data.0.replies');
});

it('does not send any notifications when a user replies to their own comment', function () {
    Notification::fake();

    $postOwner = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    $commenter = User::factory()->create();
    $parentComment = Comment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $commenter->id,
    ]);

    $this->actingAs($commenter)
        ->postJson("/api/posts/{$post->id}/comments", [
            'body' => 'Replying to myself',
            'parent_comment_id' => $parentComment->id,
        ])
        ->assertCreated();

    Notification::assertNotSentTo($postOwner, PostCommented::class);
    Notification::assertNotSentTo($commenter, CommentReplied::class);
});
