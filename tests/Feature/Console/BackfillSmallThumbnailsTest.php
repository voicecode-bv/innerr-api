<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('backfills small thumbnails for posts and avatars without one', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $avatarFile = UploadedFile::fake()->image('avatar.jpg', 400, 400);
    $avatarPath = "users/{$user->id}/avatars/avatar.jpg";
    Storage::disk('public')->putFileAs("users/{$user->id}/avatars", $avatarFile, 'avatar.jpg');
    $user->forceFill(['avatar' => $avatarPath, 'avatar_thumbnail' => null])->save();

    $postMedia = UploadedFile::fake()->image('post.jpg', 800, 800);
    $postPath = "users/{$user->id}/posts/post.jpg";
    Storage::disk('public')->putFileAs("users/{$user->id}/posts", $postMedia, 'post.jpg');
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'media_type' => 'image',
        'media_url' => $postPath,
        'thumbnail_small_url' => null,
    ]);

    $alreadyDone = Post::factory()->create([
        'media_type' => 'image',
        'thumbnail_small_url' => 'already/set.jpg',
    ]);

    $this->artisan('thumbnails:backfill-small')->assertSuccessful();

    $user->refresh();
    $post->refresh();
    $alreadyDone->refresh();

    expect($user->avatar_thumbnail)->not->toBeNull()
        ->and($post->thumbnail_small_url)->not->toBeNull()
        ->and($alreadyDone->thumbnail_small_url)->toBe('already/set.jpg');

    Storage::disk('public')->assertExists($user->avatar_thumbnail);
    Storage::disk('public')->assertExists($post->thumbnail_small_url);

    [$width, $height] = getimagesize(Storage::disk('public')->path($user->avatar_thumbnail));
    expect($width)->toBe(100)->and($height)->toBe(100);

    [$width, $height] = getimagesize(Storage::disk('public')->path($post->thumbnail_small_url));
    expect($width)->toBe(100)->and($height)->toBe(100);
});

it('skips posts where the source media is missing', function () {
    Storage::fake('public');

    $post = Post::factory()->create([
        'media_type' => 'image',
        'media_url' => 'users/1/posts/missing.jpg',
        'thumbnail_small_url' => null,
    ]);

    $this->artisan('thumbnails:backfill-small')->assertSuccessful();

    expect($post->fresh()->thumbnail_small_url)->toBeNull();
});
