<?php

use App\Enums\NotificationPreference;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLiked;
use App\Notifications\CommentReplied;
use App\Notifications\NewCirclePost;
use App\Notifications\PostCommented;
use App\Notifications\PostLiked;
use App\Notifications\PostTagged;
use NotificationChannels\Fcm\FcmChannel;

function userWithDeviceToken(array $preferences = []): User
{
    $user = User::factory()->create(
        $preferences !== [] ? ['notification_preferences' => $preferences] : [],
    );

    $user->deviceTokens()->create(['token' => 'token-'.$user->id, 'last_used_at' => now()]);

    return $user;
}

it('includes fcm channel when preference is enabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_liked'] = true;

    $user = userWithDeviceToken($preferences);

    $notification = new PostLiked(new User, new Post);

    expect($notification->via($user))->toContain(FcmChannel::class);
});

it('excludes fcm channel when preference is disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_liked'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new PostLiked(new User, new Post);

    expect($notification->via($user))->not->toContain(FcmChannel::class)
        ->and($notification->via($user))->toContain('database');
});

it('excludes fcm for post_commented when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_commented'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new PostCommented(new User, new Post, new Comment);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('excludes fcm for comment_replied when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['comment_replied'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new CommentReplied(new User, new Post, new Comment);

    expect($notification->via($user))->not->toContain(FcmChannel::class)
        ->and($notification->via($user))->toContain('database');
});

it('excludes fcm for comment_liked when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['comment_liked'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new CommentLiked(new User, new Comment);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('excludes fcm for new_circle_post when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['new_circle_post'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new NewCirclePost(new User, new Post);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('excludes fcm for post_tagged when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_tagged'] = false;

    $user = userWithDeviceToken($preferences);

    $notification = new PostTagged(new User, new Post);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('respects default preferences for each notification type', function () {
    $user = userWithDeviceToken();

    $enabledByDefault = [
        new PostLiked(new User, new Post),
        new PostCommented(new User, new Post, new Comment),
        new CommentReplied(new User, new Post, new Comment),
        new CommentLiked(new User, new Comment),
        new NewCirclePost(new User, new Post),
        new PostTagged(new User, new Post),
    ];

    foreach ($enabledByDefault as $notification) {
        expect($notification->via($user))->toContain(FcmChannel::class);
    }
});
