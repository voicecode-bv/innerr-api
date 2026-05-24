<?php

use App\Enums\MediaStatus;
use App\Jobs\ProcessPostImage;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('defers image processing to a job and returns the media as processing', function () {
    Bus::fake([ProcessPostImage::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media_status', 'processing')
        ->assertJsonPath('data.media.0.status', 'processing')
        ->assertJsonPath('data.media.0.thumbnail_url', null);

    Bus::assertDispatchedTimes(ProcessPostImage::class, 2);

    $items = Post::first()->media()->orderBy('sort_order')->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('status')->all())->toBe([MediaStatus::Processing, MediaStatus::Processing])
        ->and($items->pluck('thumbnail_path')->all())->toBe([null, null]);

    // The raw upload is persisted so the queued job has a source to work from.
    Storage::disk('public')->assertExists($items->first()->path);
});

it('processes a stored image into display + thumbnails and marks it ready', function () {
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
    $rawPath = $media->path;

    (new ProcessPostImage($media))->handle(app(MediaUploadService::class));

    $media->refresh();
    $disk = Storage::disk('public');

    expect($media->status)->toBe(MediaStatus::Ready)
        ->and($media->thumbnail_path)->not->toBeNull()
        ->and($media->thumbnail_small_path)->not->toBeNull()
        ->and($media->path)->not->toBe($rawPath);

    $disk->assertExists($media->path);
    $disk->assertExists($media->thumbnail_path);
    $disk->assertExists($media->thumbnail_small_path);
    $disk->assertMissing($rawPath);

    // PostMediaObserver mirrored the ready state onto the post's shadow columns.
    $post = Post::first();

    expect($post->media_status)->toBe(MediaStatus::Ready)
        ->and($post->media_url)->toBe($media->path)
        ->and($post->thumbnail_small_url)->toBe($media->thumbnail_small_path);
});

it('keeps the original and marks ready when processing fails', function () {
    Bus::fake([ProcessPostImage::class]);
    Storage::fake('public');

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('photo.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    $media = PostMedia::first();
    $rawPath = $media->path;

    $failing = new class extends MediaUploadService
    {
        public function processStoredPostImage(string $rawPath, string $userId, string $folder): array
        {
            throw new RuntimeException('decode failed');
        }
    };

    (new ProcessPostImage($media))->handle($failing);

    $media->refresh();

    expect($media->status)->toBe(MediaStatus::Ready)
        ->and($media->path)->toBe($rawPath);

    Storage::disk('public')->assertExists($rawPath);
});
