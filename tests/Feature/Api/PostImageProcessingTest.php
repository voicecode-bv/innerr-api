<?php

use App\Enums\MediaStatus;
use App\Jobs\ProcessPostImage;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Support\UserStorage;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

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

it('backfills taken_at and coordinates from exif when the row has none', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'taken_at' => null,
        'coordinates' => null,
    ]);

    // Simulate a HEIC upload: the synchronous create-post extraction couldn't
    // read it, so the row landed with null metadata. The stored raw file does
    // carry EXIF (here a JPEG fixture stands in for the converted HEIC).
    $rawPath = "users/{$user->id}/posts/raw-exif.jpg";
    $disk = Storage::disk('public');
    $disk->put($rawPath, file_get_contents(base_path('tests/fixtures/photo-with-exif.jpg')));
    UserStorage::trackPut($rawPath, $disk);

    $media = $post->media()->create([
        'sort_order' => 0,
        'path' => $rawPath,
        'type' => 'image',
        'status' => MediaStatus::Processing,
        'taken_at' => null,
        'coordinates' => null,
    ]);

    (new ProcessPostImage($media))->handle(app(MediaUploadService::class));

    $media->refresh();

    expect($media->status)->toBe(MediaStatus::Ready)
        ->and($media->taken_at)->not->toBeNull()
        ->and($media->taken_at->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00')
        ->and($media->coordinates)->not->toBeNull()
        ->and($media->coordinates->latitude)->toEqualWithDelta(48.858331, 0.00001)
        ->and($media->coordinates->longitude)->toEqualWithDelta(2.294497, 0.00001);

    // The observer mirrors the recovered metadata onto the post shadow columns.
    $post->refresh();

    expect($post->taken_at)->not->toBeNull()
        ->and($post->coordinates?->latitude)->toEqualWithDelta(48.858331, 0.00001);
});

it('keeps client-supplied metadata and never overwrites it from exif', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $rawPath = "users/{$user->id}/posts/raw-exif.jpg";
    $disk = Storage::disk('public');
    $disk->put($rawPath, file_get_contents(base_path('tests/fixtures/photo-with-exif.jpg')));
    UserStorage::trackPut($rawPath, $disk);

    // Client already supplied its own (different) metadata at upload time.
    $media = $post->media()->create([
        'sort_order' => 0,
        'path' => $rawPath,
        'type' => 'image',
        'status' => MediaStatus::Processing,
        'taken_at' => Carbon::parse('2020-01-02 03:04:05', 'UTC'),
        'coordinates' => new Point(10.0, 20.0, Srid::WGS84->value),
    ]);

    (new ProcessPostImage($media))->handle(app(MediaUploadService::class));

    $media->refresh();

    expect($media->status)->toBe(MediaStatus::Ready)
        ->and($media->taken_at->format('Y-m-d H:i:s'))->toBe('2020-01-02 03:04:05')
        ->and($media->coordinates->latitude)->toEqualWithDelta(10.0, 0.00001)
        ->and($media->coordinates->longitude)->toEqualWithDelta(20.0, 0.00001);
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
