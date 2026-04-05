<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

it('creates a notification when a post is liked', function () {
    $postOwner = User::factory()->create();
    $liker = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);

    $this->actingAs($liker)
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated();

    expect($postOwner->notifications)->toHaveCount(1);
    expect($postOwner->notifications->first()->type)->toBe('post-liked');
    expect($postOwner->notifications->first()->data['user_id'])->toBe($liker->id);
});

it('does not create duplicate notifications when liking a post twice', function () {
    $postOwner = User::factory()->create();
    $liker = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);

    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");

    expect($postOwner->fresh()->notifications)->toHaveCount(1);
});

it('creates a notification when a comment is added to a post', function () {
    $postOwner = User::factory()->create();
    $commenter = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);

    $this->actingAs($commenter)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'Nice photo!'])
        ->assertCreated();

    expect($postOwner->notifications)->toHaveCount(1);
    expect($postOwner->notifications->first()->type)->toBe('post-commented');
    expect($postOwner->notifications->first()->data['comment_body'])->toBe('Nice photo!');
});

it('does not notify post owner when they comment on their own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/comments", ['body' => 'My own comment'])
        ->assertCreated();

    expect($user->notifications)->toHaveCount(0);
});

it('creates a notification when a comment is liked', function () {
    $commentOwner = User::factory()->create();
    $liker = User::factory()->create();
    $post = Post::factory()->create();
    $comment = Comment::factory()->create(['user_id' => $commentOwner->id, 'post_id' => $post->id]);

    $this->actingAs($liker)
        ->postJson("/api/comments/{$comment->id}/like")
        ->assertCreated();

    expect($commentOwner->notifications)->toHaveCount(1);
    expect($commentOwner->notifications->first()->type)->toBe('comment-liked');
    expect($commentOwner->notifications->first()->data['user_id'])->toBe($liker->id);
});

it('returns paginated notifications for the authenticated user', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    // Create notifications by liking the post from different users
    User::factory(3)->create()->each(function (User $liker) use ($post) {
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    });

    $this->actingAs($user)
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'type', 'data', 'read_at', 'created_at'],
            ],
        ]);
});

it('can mark all notifications as read', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    User::factory(2)->create()->each(function (User $liker) use ($post) {
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    });

    $this->actingAs($user)
        ->postJson('/api/notifications/read')
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('can mark specific notifications as read', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    User::factory(3)->create()->each(function (User $liker) use ($post) {
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    });

    $notificationId = $user->notifications->first()->id;

    $this->actingAs($user)
        ->postJson('/api/notifications/read', ['ids' => [$notificationId]])
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(2);
    expect($user->readNotifications()->count())->toBe(1);
});

it('requires authentication to view notifications', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

it('requires authentication to mark notifications as read', function () {
    $this->postJson('/api/notifications/read')->assertUnauthorized();
});
