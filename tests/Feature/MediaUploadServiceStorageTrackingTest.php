<?php

use App\Actions\AnonymizeUser;
use App\Models\User;
use App\Services\MediaUploadService;
use App\Support\UserStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('increases storage usage for an image upload (display + original)', function () {
    Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    app(MediaUploadService::class)->store(
        UploadedFile::fake()->image('photo.jpg', width: 800, height: 600),
        $user->id,
        'posts',
    );

    expect($user->fresh()->storage_used_bytes)->toBeGreaterThan(0);
});

it('decreases storage usage when deleting both display and original', function () {
    Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    $media = app(MediaUploadService::class);

    $path = $media->store(
        UploadedFile::fake()->image('photo.jpg', width: 800, height: 600),
        $user->id,
        'posts',
    );

    $afterUpload = $user->fresh()->storage_used_bytes;
    expect($afterUpload)->toBeGreaterThan(0);

    $media->delete($path);

    expect($user->fresh()->storage_used_bytes)->toBe(0);
});

it('resets storage usage when a user is anonymised', function () {
    Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    app(MediaUploadService::class)->store(
        UploadedFile::fake()->image('photo.jpg', width: 800, height: 600),
        $user->id,
        'posts',
    );

    expect($user->fresh()->storage_used_bytes)->toBeGreaterThan(0);

    app(AnonymizeUser::class)($user->fresh());

    expect($user->fresh()->storage_used_bytes)->toBe(0);
});

it('removes the whole HLS bundle when deleting an .m3u8 path', function () {
    $disk = Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    $hlsDir = "users/{$user->id}/posts/hls/abc";
    $files = [
        "{$hlsDir}/master.m3u8" => "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1\nv1080/playlist.m3u8\n",
        "{$hlsDir}/v1080/playlist.m3u8" => "#EXTM3U\n#EXTINF:1,\nseg-001.ts\n",
        "{$hlsDir}/v1080/seg-001.ts" => str_repeat('a', 1024),
        "{$hlsDir}/v720/playlist.m3u8" => "#EXTM3U\n#EXTINF:1,\nseg-001.ts\n",
        "{$hlsDir}/v720/seg-001.ts" => str_repeat('b', 512),
    ];

    foreach ($files as $path => $contents) {
        $disk->put($path, $contents);
        UserStorage::trackPut($path, $disk);
    }

    $original = "users/{$user->id}/originals/posts/source.mp4";
    $disk->put($original, str_repeat('c', 2048));
    UserStorage::trackPut($original, $disk);

    expect($user->fresh()->storage_used_bytes)->toBeGreaterThan(0);

    app(MediaUploadService::class)->delete("{$hlsDir}/master.m3u8", $original);

    foreach (array_keys($files) as $path) {
        expect($disk->exists($path))->toBeFalse();
    }
    expect($disk->exists($original))->toBeFalse();
    expect($user->fresh()->storage_used_bytes)->toBe(0);
});

it('falls back to derived original path for non-HLS uploads', function () {
    $disk = Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    $display = "users/{$user->id}/posts/clip.mp4";
    $original = "users/{$user->id}/originals/posts/clip.mp4";

    $disk->put($display, str_repeat('x', 1024));
    $disk->put($original, str_repeat('y', 2048));
    UserStorage::trackPut($display, $disk);
    UserStorage::trackPut($original, $disk);

    app(MediaUploadService::class)->delete($display);

    expect($disk->exists($display))->toBeFalse();
    expect($disk->exists($original))->toBeFalse();
    expect($user->fresh()->storage_used_bytes)->toBe(0);
});
