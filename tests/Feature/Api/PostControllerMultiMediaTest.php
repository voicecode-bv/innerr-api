<?php

use App\Jobs\SubmitVideoToFileFlux;
use App\Jobs\TranscodeVideo;
use App\Models\Circle;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

it('stores a post with multiple photos and creates ordered post_media rows', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
                UploadedFile::fake()->image('c.jpg'),
            ],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media_type', 'image')
        ->assertJsonCount(3, 'data.media');

    $post = Post::first();
    $items = $post->media()->orderBy('sort_order')->get();

    expect($items)->toHaveCount(3)
        ->and($items->pluck('sort_order')->all())->toBe([0, 1, 2])
        ->and($items->pluck('type')->all())->toBe(['image', 'image', 'image']);

    // Shadow columns mirror the first item.
    expect($post->media_url)->toBe($items->first()->path)
        ->and($post->thumbnail_small_url)->toBe($items->first()->thumbnail_small_path);
});

it('uses top-level coordinates as the post position for a multi-photo post', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
            'latitude' => 52.370216,
            'longitude' => 4.895168,
            'location' => 'Amsterdam',
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.latitude', 52.370216)
        ->assertJsonPath('data.longitude', 4.895168)
        ->assertJsonPath('data.location', 'Amsterdam');

    $post = Post::first();
    expect($post->latitude)->toEqualWithDelta(52.370216, 0.0001)
        ->and($post->longitude)->toEqualWithDelta(4.895168, 0.0001);
});

it('merges client-provided metadata with extracted EXIF per item', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
            'media_metadata' => json_encode([
                ['taken_at' => '2025-01-01T10:00:00Z', 'latitude' => 52.37, 'longitude' => 4.89],
                ['latitude' => 48.85, 'longitude' => 2.35],
            ]),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    $items = Post::first()->media()->orderBy('sort_order')->get();

    expect($items[0]->latitude)->toBe(52.37)
        ->and($items[0]->longitude)->toBe(4.89)
        ->and($items[0]->taken_at?->toIso8601String())->toBe('2025-01-01T10:00:00+00:00')
        ->and($items[1]->latitude)->toBe(48.85)
        ->and($items[1]->longitude)->toBe(2.35)
        ->and($items[1]->taken_at)->toBeNull();
});

it('rejects mixing video and photos in one post', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'),
            ],
            'circle_ids' => [$circle->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media.1');
});

it('rejects more than 10 media items', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $media = [];
    for ($i = 0; $i < 11; $i++) {
        $media[] = UploadedFile::fake()->image("a{$i}.jpg");
    }

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => $media,
            'circle_ids' => [$circle->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('media');
});

it('exposes the media[] array in PostResource ordered by sort_order', function () {
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
        ->assertCreated();

    $postId = Post::first()->id;

    $response = $this->actingAs($user)
        ->getJson("/api/posts/{$postId}")
        ->assertOk()
        ->json('data.media');

    expect($response)->toHaveCount(2)
        ->and($response[0]['sort_order'])->toBe(0)
        ->and($response[1]['sort_order'])->toBe(1)
        ->and($response[0])->toHaveKeys(['id', 'url', 'type', 'status', 'thumbnail_small_url', 'taken_at']);
});

it('cascades media deletion when deleting a post', function () {
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
        ->assertCreated();

    $post = Post::first();
    $paths = $post->media()->pluck('path')->all();

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $this->actingAs($user)
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect(PostMedia::query()->where('post_id', $post->id)->exists())->toBeFalse();

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

it('keeps single-mode posts working with one post_media row', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->image('legacy.jpg'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonCount(1, 'data.media')
        ->assertJsonPath('data.media.0.sort_order', 0);

    $post = Post::first();

    expect($post->media)->toHaveCount(1)
        ->and($post->media_url)->toBe($post->media->first()->path);
});

it('dispatches TranscodeVideo for each video item with the PostMedia model', function () {
    config(['services.fileflux.enabled' => false]);
    Bus::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    Bus::assertDispatched(TranscodeVideo::class, function (TranscodeVideo $job) {
        return $job->postMedia instanceof PostMedia;
    });
});

it('dispatches SubmitVideoToFileFlux when fileflux is enabled', function () {
    config(['services.fileflux.enabled' => true]);
    Bus::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'),
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    Bus::assertDispatched(SubmitVideoToFileFlux::class, function ($job) {
        return $job->postMedia instanceof PostMedia;
    });
    Bus::assertNotDispatched(TranscodeVideo::class);
});

it('applies HEIC orientation correction per item in multi-photo posts', function () {
    if (! class_exists(Imagick::class) || ! in_array('HEIC', Imagick::queryFormats('HEIC'), true)) {
        $this->markTestSkipped('Imagick build lacks HEIC support');
    }

    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $heicFixture = new UploadedFile(
        __DIR__.'/../../fixtures/photo-heic-orientation-mismatch.heic',
        'photo.heic',
        'image/heic',
        null,
        true,
    );

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [
                $heicFixture,
                UploadedFile::fake()->image('plain.jpg'),
            ],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    $post = Post::first();
    $disk = Storage::disk('public');

    // HEIC is item 0 — its display variant must be rotated + EXIF reset.
    $heicItem = $post->media()->where('sort_order', 0)->first();
    $heicPath = $disk->path($heicItem->path);
    [$width] = getimagesize($heicPath);

    // Source HEIC display variant is scaled down to MAX_DISPLAY_WIDTH (1920).
    expect($width)->toBe(1920);

    $exif = @exif_read_data($heicPath, 'IFD0', true);
    expect($exif['IFD0']['Orientation'] ?? 1)->toBe(1);

    // Plain JPEG is item 1 — should be stored normally.
    $jpegItem = $post->media()->where('sort_order', 1)->first();
    expect($disk->exists($jpegItem->path))->toBeTrue();
});

it('observer syncs shadow columns when the primary post_media item changes', function () {
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
        ->assertCreated();

    $post = Post::first();
    $primary = $post->media()->where('sort_order', 0)->first();

    $primary->update(['path' => 'users/new/path.jpg']);

    expect($post->fresh()->media_url)->toBe('users/new/path.jpg');
});
