<?php

use App\Enums\NotificationPreference;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLiked;
use App\Notifications\NewCirclePost;
use App\Notifications\PostCommented;
use App\Notifications\PostLiked;
use NotificationChannels\Fcm\FcmChannel;

it('includes fcm channel when preference is enabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_liked'] = true;

    $user = new User(['fcm_token' => 'token', 'notification_preferences' => $preferences]);

    $notification = new PostLiked(new User, new Post);

    expect($notification->via($user))->toContain(FcmChannel::class);
});

it('excludes fcm channel when preference is disabled', function () {
    $user = new User(['fcm_token' => 'token']);

    $notification = new PostLiked(new User, new Post);

    expect($notification->via($user))->not->toContain(FcmChannel::class)
        ->and($notification->via($user))->toContain('database');
});

it('excludes fcm for post_commented when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['post_commented'] = false;

    $user = new User(['fcm_token' => 'token', 'notification_preferences' => $preferences]);

    $notification = new PostCommented(new User, new Post, new Comment);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('excludes fcm for comment_liked when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['comment_liked'] = false;

    $user = new User(['fcm_token' => 'token', 'notification_preferences' => $preferences]);

    $notification = new CommentLiked(new User, new Comment);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('excludes fcm for new_circle_post when disabled', function () {
    $preferences = NotificationPreference::defaults();
    $preferences['new_circle_post'] = false;

    $user = new User(['fcm_token' => 'token', 'notification_preferences' => $preferences]);

    $notification = new NewCirclePost(new User, new Post);

    expect($notification->via($user))->not->toContain(FcmChannel::class);
});

it('respects default preferences for each notification type', function () {
    $user = new User(['fcm_token' => 'token']);

    $enabledByDefault = [
        new PostCommented(new User, new Post, new Comment),
        new CommentLiked(new User, new Comment),
        new NewCirclePost(new User, new Post),
    ];

    foreach ($enabledByDefault as $notification) {
        expect($notification->via($user))->toContain(FcmChannel::class);
    }

    $disabledByDefault = new PostLiked(new User, new Post);
    expect($disabledByDefault->via($user))->not->toContain(FcmChannel::class);
});
