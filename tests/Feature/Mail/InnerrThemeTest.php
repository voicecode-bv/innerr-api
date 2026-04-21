<?php

use App\Models\User;
use App\Notifications\GdprExportReady;
use Illuminate\Support\Facades\Storage;

it('renders mail notifications with the Innerr theme palette', function () {
    config()->set('gdpr.export.disk', 'exports');
    config()->set('gdpr.export.directory', 'gdpr-exports');
    config()->set('gdpr.export.expiry_hours', 24);
    Storage::fake('exports');
    Storage::disk('exports')->put('gdpr-exports/1/fake.zip', 'x');

    $user = User::factory()->create();
    $mailMessage = (new GdprExportReady('gdpr-exports/1/fake.zip'))->toMail($user);
    $html = (string) $mailMessage->render();

    expect($html)
        ->toContain('#1D5F5C')
        ->toContain('#FEFAF3')
        ->toContain('Playfair Display')
        ->toContain('DM Sans');
});

it('uses the innerr theme as the configured markdown theme', function () {
    expect(config('mail.markdown.theme'))->toBe('innerr');
});
