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
    Storage::fake(config('filesystems.media'));
    Notification::fake();
});

it('writes a ZIP containing data.json and all user media to the export disk', function () {
    $user = User::factory()->create(['email' => 'alice@example.com']);

    $media = Storage::disk(config('filesystems.media'));
    $media->put("users/{$user->id}/avatars/me.jpg", 'avatar-bytes');
    $media->put("users/{$user->id}/posts/one.jpg", 'post-one-bytes');
    $media->put("users/{$user->id}/originals/posts/one.mov", 'original-bytes');

    (new ExportUserData($user))->handle();

    $exports = Storage::disk('exports');
    $files = $exports->allFiles("gdpr-exports/{$user->id}");
    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.zip');

    $localZip = tempnam(sys_get_temp_dir(), 'gdpr_test_').'.zip';
    file_put_contents($localZip, $exports->get($files[0]));

    $zip = new ZipArchive;
    expect($zip->open($localZip))->toBeTrue();

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    expect($entries)->toContain(
        'data.json',
        'media/avatars/me.jpg',
        'media/posts/one.jpg',
        'media/originals/posts/one.mov',
    );

    $payload = json_decode($zip->getFromName('data.json'), true);
    expect($payload['email'])->toBe('alice@example.com')
        ->and($payload)->not->toHaveKey('password')
        ->and($payload)->not->toHaveKey('fcm_token');

    expect($zip->getFromName('media/posts/one.jpg'))->toBe('post-one-bytes')
        ->and($zip->getFromName('media/originals/posts/one.mov'))->toBe('original-bytes');

    $zip->close();
    @unlink($localZip);
});

it('produces a ZIP with only data.json when the user has no media', function () {
    $user = User::factory()->create();

    (new ExportUserData($user))->handle();

    $exports = Storage::disk('exports');
    $files = $exports->allFiles("gdpr-exports/{$user->id}");
    expect($files)->toHaveCount(1);

    $localZip = tempnam(sys_get_temp_dir(), 'gdpr_test_').'.zip';
    file_put_contents($localZip, $exports->get($files[0]));

    $zip = new ZipArchive;
    $zip->open($localZip);

    expect($zip->numFiles)->toBe(1)
        ->and($zip->getNameIndex(0))->toBe('data.json');

    $zip->close();
    @unlink($localZip);
});

it('notifies the user with the stored ZIP path', function () {
    $user = User::factory()->create();

    (new ExportUserData($user))->handle();

    Notification::assertSentTo($user, GdprExportReady::class, function ($notification) use ($user) {
        return str_starts_with($notification->path, "gdpr-exports/{$user->id}/")
            && str_ends_with($notification->path, '.zip');
    });
});
