<?php

use App\Jobs\ExportUserData;
use App\Models\User;
use App\Notifications\GdprExportReady;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('gdpr.export.disk', 'exports');
    config()->set('gdpr.export.directory', 'gdpr-exports');
    config()->set('gdpr.export.expiry_hours', 24);

    Storage::fake('exports');
    Notification::fake();
});

it('writes a JSON export file to the configured disk', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);

    (new ExportUserData($user))->handle();

    $disk = Storage::disk('exports');
    $files = $disk->allFiles("gdpr-exports/{$user->id}");

    expect($files)->toHaveCount(1);

    $contents = $disk->get($files[0]);
    $payload = json_decode($contents, true);

    expect($payload)
        ->toBeArray()
        ->and($payload['email'])->toBe('alice@example.com')
        ->and($payload)->not->toHaveKey('password')
        ->and($payload)->not->toHaveKey('fcm_token');
});

it('notifies the user with the stored path', function () {
    $user = User::factory()->create();

    (new ExportUserData($user))->handle();

    Notification::assertSentTo($user, GdprExportReady::class, function ($notification) use ($user) {
        return str_starts_with($notification->path, "gdpr-exports/{$user->id}/")
            && str_ends_with($notification->path, '.json');
    });
});
