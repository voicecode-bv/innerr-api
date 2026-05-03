<?php

use App\Models\User;
use App\Support\UserStorage;
use Illuminate\Support\Facades\Storage;

it('extracts the user id from a users/{id}/... path', function () {
    $uuid1 = '019deefc-1234-7000-8000-abcdef012345';
    $uuid2 = '019deefc-9999-7000-8000-abcdef012345';

    expect(UserStorage::userIdFromPath("users/{$uuid1}/posts/foo.jpg"))->toBe($uuid1)
        ->and(UserStorage::userIdFromPath("users/{$uuid2}/originals/posts/bar.mp4"))->toBe($uuid2)
        ->and(UserStorage::userIdFromPath('shared/avatar.jpg'))->toBeNull()
        ->and(UserStorage::userIdFromPath('users/abc/foo.jpg'))->toBeNull()
        ->and(UserStorage::userIdFromPath('users/42/foo.jpg'))->toBeNull();
});

it('atomically increments and decrements user storage', function () {
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    UserStorage::adjust($user->id, 1024);
    expect($user->fresh()->storage_used_bytes)->toBe(1024);

    UserStorage::adjust($user->id, 512);
    expect($user->fresh()->storage_used_bytes)->toBe(1536);

    UserStorage::adjust($user->id, -1024);
    expect($user->fresh()->storage_used_bytes)->toBe(512);
});

it('clamps usage at zero on over-decrement', function () {
    $user = User::factory()->create(['storage_used_bytes' => 100]);

    UserStorage::adjust($user->id, -500);

    expect($user->fresh()->storage_used_bytes)->toBe(0);
});

it('tracks a put using the actual stored file size', function () {
    Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 0]);

    $path = "users/{$user->id}/posts/example.bin";
    Storage::disk('public')->put($path, str_repeat('a', 2048));

    UserStorage::trackPut($path, Storage::disk('public'));

    expect($user->fresh()->storage_used_bytes)->toBe(2048);
});

it('tracks a delete using the size on disk before removal', function () {
    Storage::fake('public');
    $user = User::factory()->create(['storage_used_bytes' => 5000]);

    $path = "users/{$user->id}/posts/example.bin";
    $disk = Storage::disk('public');
    $disk->put($path, str_repeat('a', 1500));

    UserStorage::trackDelete($path, $disk);

    expect($user->fresh()->storage_used_bytes)->toBe(3500);
});

it('ignores paths outside the users/ prefix', function () {
    $user = User::factory()->create(['storage_used_bytes' => 100]);

    UserStorage::trackPut('shared/somefile.jpg');

    expect($user->fresh()->storage_used_bytes)->toBe(100);
});

it('resets a user back to zero', function () {
    $user = User::factory()->create(['storage_used_bytes' => 9999]);

    UserStorage::reset($user->id);

    expect($user->fresh()->storage_used_bytes)->toBe(0);
});
