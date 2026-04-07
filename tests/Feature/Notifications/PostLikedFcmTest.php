<?php

use App\Models\Post;
use App\Models\User;
use App\Notifications\PostLiked;
use NotificationChannels\Fcm\FcmMessage;

it('builds an fcm message with the liker name and post id', function () {
    $liker = new User(['name' => 'Alice']);
    $liker->id = 42;

    $post = new Post;
    $post->id = 7;

    $owner = new User(['fcm_token' => 'token']);

    $message = (new PostLiked($liker, $post))->toFcm($owner);

    expect($message)->toBeInstanceOf(FcmMessage::class);

    $payload = $message->toArray();

    expect($payload['notification']['title'] ?? null)->toBe('Alice')
        ->and($payload['data']['type'] ?? null)->toBe('post-liked')
        ->and($payload['data']['post_id'] ?? null)->toBe('7')
        ->and($payload['data']['user_id'] ?? null)->toBe('42');
});
