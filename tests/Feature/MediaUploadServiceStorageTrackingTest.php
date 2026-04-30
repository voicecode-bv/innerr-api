<?php

use App\Actions\AnonymizeUser;
use App\Models\User;
use App\Services\MediaUploadService;
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
