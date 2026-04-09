<?php

use App\Enums\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostLiked;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Fcm\FcmChannel;

it('cannot like own post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/api/posts/{$post->id}/like")
        ->assertForbidden();

    $this->assertDatabaseMissing('likes', [
        'likeable_id' => $post->id,
        'likeable_type' => Post::class,
    ]);
});

it('can like another users post', function () {
    $post = Post::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated()
        ->assertJsonPath('liked', true)
        ->assertJsonPath('likes_count', 1);
});

it('sends a push notification when the post owner has an fcm token', function () {
    Notification::fake();

    $preferences = NotificationPreference::defaults();
    $preferences['post_liked'] = true;

    $owner = User::factory()->create(['fcm_token' => 'test-token', 'notification_preferences' => $preferences]);
    $post = Post::factory()->create(['user_id' => $owner->id]);
    $liker = User::factory()->create();

    $this->actingAs($liker)
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated();

    Notification::assertSentTo(
        $owner,
        PostLiked::class,
        fn (PostLiked $notification) => in_array(FcmChannel::class, $notification->via($owner), true),
    );
});

it('does not include the fcm channel when the post owner has no fcm token', function () {
    Notification::fake();

    $owner = User::factory()->create(['fcm_token' => null]);
    $post = Post::factory()->create(['user_id' => $owner->id]);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated();

    Notification::assertSentTo(
        $owner,
        PostLiked::class,
        fn (PostLiked $notification) => ! in_array(FcmChannel::class, $notification->via($owner), true),
    );
});
