<?php

use App\Models\Comment;
use App\Models\Like;
use App\Models\User;

it('increments the user likes_count when a like is created', function () {
    $user = User::factory()->create();

    Like::factory()->count(3)->create(['user_id' => $user->id]);

    expect($user->refresh()->likes_count)->toBe(3);
});

it('decrements the user likes_count when a like is deleted', function () {
    $user = User::factory()->create();
    $likes = Like::factory()->count(2)->create(['user_id' => $user->id]);

    $likes->first()->delete();

    expect($user->refresh()->likes_count)->toBe(1);
});

it('increments the user comments_count when a comment is created', function () {
    $user = User::factory()->create();

    Comment::factory()->count(4)->create(['user_id' => $user->id]);

    expect($user->refresh()->comments_count)->toBe(4);
});

it('decrements the user comments_count when a comment is deleted', function () {
    $user = User::factory()->create();
    $comments = Comment::factory()->count(2)->create(['user_id' => $user->id]);

    $comments->first()->delete();

    expect($user->refresh()->comments_count)->toBe(1);
});

it('counts likes on comments toward the giver\'s likes_count', function () {
    $user = User::factory()->create();
    $comment = Comment::factory()->create();

    Like::factory()->create([
        'user_id' => $user->id,
        'likeable_id' => $comment->id,
        'likeable_type' => Comment::class,
    ]);

    expect($user->refresh()->likes_count)->toBe(1);
});
