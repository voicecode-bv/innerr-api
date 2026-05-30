<?php

use App\Enums\MediaStatus;
use App\Jobs\BackfillMediaDimensions;
use App\Jobs\ProcessPostImage;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('captures orientation-corrected image dimensions when processing a post image', function () {
    Bus::fake([ProcessPostImage::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg', 2000, 1500),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    $media = PostMedia::first();

    // Dimensions are unknown until the deferred job reads the oriented image.
    expect($media->width)->toBeNull()
        ->and($media->height)->toBeNull();

    (new ProcessPostImage($media))->handle(app(MediaUploadService::class));

    $media->refresh();

    expect($media->width)->toBe(2000)
        ->and($media->height)->toBe(1500);
});

it('exposes media dimensions on the post resource at both levels', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $post->media()->create([
        'sort_order' => 0,
        'path' => 'users/x/posts/a.jpg',
        'type' => 'image',
        'status' => MediaStatus::Ready,
        'width' => 1080,
        'height' => 1920,
    ]);

    $this->actingAs($user)
        ->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.width', 1080)
        ->assertJsonPath('data.height', 1920)
        ->assertJsonPath('data.media.0.width', 1080)
        ->assertJsonPath('data.media.0.height', 1920);
});

it('does not crash probing dimensions of a non-video upload', function () {
    $dimensions = app(MediaUploadService::class)->probeVideoDimensions(
        UploadedFile::fake()->create('clip.mp4', 64)->getPathname(),
    );

    expect($dimensions)->toBe(['width' => null, 'height' => null]);
});

it('backfills dimensions for ready media missing width', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $disk = Storage::disk('public');
    $displayPath = "users/{$user->id}/posts/legacy.jpg";
    $disk->put($displayPath, UploadedFile::fake()->image('legacy.jpg', 800, 600)->getContent());

    $media = $post->media()->create([
        'sort_order' => 0,
        'path' => $displayPath,
        'type' => 'image',
        'status' => MediaStatus::Ready,
        'width' => null,
        'height' => null,
    ]);

    (new BackfillMediaDimensions([$media->id]))->handle();

    $media->refresh();

    expect($media->width)->toBe(800)
        ->and($media->height)->toBe(600);
});
