<?php

use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->postJson('/api/support', ['message' => 'Help'])
        ->assertUnauthorized();
});

it('emails the support inbox with the message, version and platform', function () {
    Mail::fake();

    config(['mail.support_address' => 'hallo@innerr.app']);

    $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    Sanctum::actingAs($user);

    $this->postJson('/api/support', [
        'message' => 'The app keeps crashing on launch.',
        'app_version' => '1.2.3',
        'platform' => 'ios',
    ])->assertCreated()
        ->assertJsonPath('message', 'Support request received.');

    Mail::assertQueued(SupportRequestMail::class, function (SupportRequestMail $mail) use ($user): bool {
        return $mail->hasTo('hallo@innerr.app')
            && $mail->hasReplyTo('jane@example.com')
            && $mail->supportMessage === 'The app keeps crashing on launch.'
            && $mail->appVersion === '1.2.3'
            && $mail->platform === 'ios'
            && $mail->sender->is($user);
    });
});

it('accepts a support request without version or platform', function () {
    Mail::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/support', [
        'message' => 'Just some feedback.',
    ])->assertCreated();

    Mail::assertQueued(SupportRequestMail::class, function (SupportRequestMail $mail): bool {
        return $mail->appVersion === null && $mail->platform === null;
    });
});

it('renders the Filament-managed template with the request details and no signature', function () {
    $user = User::factory()->create(['name' => 'Sophie de Vries', 'email' => 'sophie@example.com']);

    $mail = new SupportRequestMail(
        supportMessage: 'The map keeps crashing.',
        appVersion: '1.2.3',
        platform: 'ios',
        sender: $user,
    );

    $mail->assertHasSubject('Supportverzoek van Sophie de Vries');
    $mail->assertSeeInHtml('The map keeps crashing.');
    $mail->assertSeeInHtml('sophie@example.com');
    $mail->assertSeeInHtml('1.2.3');
    $mail->assertSeeInHtml('ios');
    // The marketing signature must not be appended to an internal support mail.
    $mail->assertDontSeeInHtml('Groetjes');
});

it('validates the request', function () {
    Mail::fake();

    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/support', [
        'message' => '',
        'platform' => 'windows',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['message', 'platform']);

    Mail::assertNothingQueued();
});
