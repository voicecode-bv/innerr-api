<?php

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Str;

it('creates a notification when a post is liked', function () {
    $postOwner = User::factory()->create();
    $liker = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    shareCircle($post, $liker);

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
    shareCircle($post, $liker);

    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");

    expect($postOwner->fresh()->notifications)->toHaveCount(1);
});

it('creates a notification when a comment is added to a post', function () {
    $postOwner = User::factory()->create();
    $commenter = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $postOwner->id]);
    shareCircle($post, $commenter);

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
    // Both the liker and the comment author must share the post's circle, else
    // the comment is invisible to the liker (PostViewerVisibility) and liking
    // it 404s by design.
    shareCircle($post, $liker, $commentOwner);
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
        shareCircle($post, $liker);
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

it('only returns unread notifications', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    User::factory(3)->create()->each(function (User $liker) use ($post) {
        shareCircle($post, $liker);
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    });

    $user->notifications->first()->markAsRead();

    $this->actingAs($user)
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('can mark all notifications as read', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    User::factory(2)->create()->each(function (User $liker) use ($post) {
        shareCircle($post, $liker);
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
        shareCircle($post, $liker);
        $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");
    });

    $notificationId = $user->notifications->first()->id;

    $this->actingAs($user)
        ->postJson('/api/notifications/read', ['ids' => [$notificationId]])
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(2);
    expect($user->readNotifications()->count())->toBe(1);
});

it('includes signed small-thumbnail URLs for avatar and post media', function () {
    $postOwner = User::factory()->create();
    $liker = User::factory()->create([
        'avatar' => 'users/1/avatars/avatar.jpg',
        'avatar_thumbnail' => 'users/1/avatars/thumbnails/avatar-sm.jpg',
    ]);
    $post = Post::factory()->create([
        'user_id' => $postOwner->id,
        'thumbnail_small_url' => 'users/2/posts/thumbnails/post-sm.jpg',
    ]);
    shareCircle($post, $liker);

    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");

    $data = $this->actingAs($postOwner)
        ->getJson('/api/notifications')
        ->assertOk()
        ->json('data.0.data');

    // Media URLs are signed via BunnyCDN's token scheme (token + expires), not
    // Laravel's signed-route `signature=` param.
    expect($data['user_avatar_thumbnail'])->toContain('avatar-sm.jpg')
        ->and($data['user_avatar_thumbnail'])->toContain('token=')
        ->and($data['post_thumbnail_small_url'])->toContain('post-sm.jpg')
        ->and($data['post_thumbnail_small_url'])->toContain('token=');
});

it('includes a signed poster URL for video posts without a small thumbnail', function () {
    $postOwner = User::factory()->create();
    $liker = User::factory()->create();
    // Videos never get a 300x300 small thumbnail; they only have the poster
    // frame (thumbnail_url), which the app falls back to for the list preview.
    $post = Post::factory()->create([
        'user_id' => $postOwner->id,
        'thumbnail_url' => 'users/2/posts/thumbnails/post-poster.jpg',
        'thumbnail_small_url' => null,
    ]);
    shareCircle($post, $liker);

    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");

    $data = $this->actingAs($postOwner)
        ->getJson('/api/notifications')
        ->assertOk()
        ->json('data.0.data');

    expect($data['post_thumbnail_small_url'])->toBeNull()
        ->and($data['post_thumbnail_url'])->toContain('post-poster.jpg')
        ->and($data['post_thumbnail_url'])->toContain('token=');
});

it('keeps actionable notifications unread when marking all as read', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $liker = User::factory()->create();
    shareCircle($post, $liker);
    $this->actingAs($liker)->postJson("/api/posts/{$post->id}/like");

    // A pending circle invitation represents an outstanding action; it must
    // survive a blanket mark-all-read so it keeps counting as unread until the
    // user accepts or declines it.
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'circle-invitation-received',
        'data' => ['invitation_id' => 1],
    ]);

    expect($user->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user)
        ->postJson('/api/notifications/read')
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(1);
    expect($user->unreadNotifications()->first()->type)->toBe('circle-invitation-received');
});

it('can still mark an actionable notification read by explicit id', function () {
    $user = User::factory()->create();

    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'circle-invitation-received',
        'data' => ['invitation_id' => 1],
    ]);

    $this->actingAs($user)
        ->postJson('/api/notifications/read', ['ids' => [$notification->id]])
        ->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('requires authentication to view notifications', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

it('requires authentication to mark notifications as read', function () {
    $this->postJson('/api/notifications/read')->assertUnauthorized();
});
