<?php

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('sends a password reset notification with a frontend url', function () {
    config()->set('app.frontend_url', 'https://innerr.app');

    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'jane@example.com',
    ])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);
        $body = (string) ($mail->viewData['body'] ?? '');

        expect($body)->toContain('https://innerr.app/password-reset?token=');
        expect($body)->toContain('&email=jane%40example.com');

        return true;
    });
});

it('returns 200 when the email is unknown to avoid enumeration', function () {
    Notification::fake();

    $this->postJson('/api/auth/forgot-password', [
        'email' => 'nobody@example.com',
    ])->assertOk();

    Notification::assertNothingSent();
});

it('validates the email field on forgot-password', function () {
    $this->postJson('/api/auth/forgot-password', [
        'email' => 'not-an-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('resets the password with a valid token', function () {
    Event::fake();

    $user = User::factory()->create(['email' => 'jane@example.com']);
    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'jane@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();

    Event::assertDispatched(PasswordReset::class);
});

it('revokes all api tokens, device tokens and sessions on password reset', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $user->createToken('mobile')->plainTextToken;
    $user->createToken('web')->plainTextToken;
    $user->deviceTokens()->create(['token' => 'fcm-token-1', 'last_used_at' => now()]);
    DB::table('sessions')->insert([
        'id' => 'session-1',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'phpunit',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'jane@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
    expect($user->deviceTokens()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('rejects an invalid reset token', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->postJson('/api/auth/reset-password', [
        'token' => 'bogus-token',
        'email' => 'jane@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('does not reveal whether an email exists on reset (no enumeration)', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    // Unknown email + any token: must return the SAME generic message as a
    // bogus token for a known user, so the response can't confirm account
    // existence.
    $unknownEmail = $this->postJson('/api/auth/reset-password', [
        'token' => 'bogus-token',
        'email' => 'nobody@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    $knownEmail = $this->postJson('/api/auth/reset-password', [
        'token' => 'bogus-token',
        'email' => 'jane@example.com',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($unknownEmail->json('errors.email.0'))
        ->toBe($knownEmail->json('errors.email.0'));
});

it('requires password confirmation on reset', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $token = app('auth.password.broker')->createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => 'jane@example.com',
        'password' => 'new-password-123',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('requires token, email and password on reset', function () {
    $this->postJson('/api/auth/reset-password', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token', 'email', 'password']);
});

it('returns the forgot-password message in the requested locale', function (string $locale, string $expected) {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->withHeader('Accept-Language', $locale)
        ->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])
        ->assertOk()
        ->assertJsonPath('message', $expected);
})->with([
    'nl' => ['nl', 'We hebben je een e-mail gestuurd met een link om je wachtwoord te resetten.'],
    'fr' => ['fr', 'Nous vous avons envoyé par e-mail le lien de réinitialisation de votre mot de passe.'],
    'en' => ['en', 'We have emailed your password reset link.'],
]);

it('returns the reset-password success message in the requested locale', function (string $locale, string $expected) {
    $user = User::factory()->create(['email' => 'jane@example.com']);
    $token = app('auth.password.broker')->createToken($user);

    $this->withHeader('Accept-Language', $locale)
        ->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertOk()
        ->assertJsonPath('message', $expected);
})->with([
    'nl' => ['nl', 'Je wachtwoord is gereset.'],
    'fr' => ['fr', 'Votre mot de passe a été réinitialisé.'],
    'en' => ['en', 'Your password has been reset.'],
]);

it('returns validation errors in the requested locale', function (string $locale, string $expected) {
    $this->withHeader('Accept-Language', $locale)
        ->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', $expected);
})->with([
    'nl' => ['nl', 'e-mailadres is geen geldig e-mailadres.'],
    'fr' => ['fr', 'Le champ adresse e-mail doit être une adresse e-mail valide.'],
    'en' => ['en', 'The email address field must be a valid email address.'],
]);

it('uses the Accept-Language header to localize the response', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $this->withHeader('Accept-Language', 'nl')
        ->postJson('/api/auth/reset-password', [
            'token' => 'bogus-token',
            'email' => 'jane@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Deze resetlink is ongeldig of verlopen.');
});

it('renders the reset email in the user locale', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jane@example.com', 'locale' => 'nl']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        // NotificationSender wraps the channel call in setLocale($notifiable->preferredLocale());
        // mirror that here so the assertion reflects what the recipient actually receives.
        App::setLocale($user->preferredLocale());
        $mail = $notification->toMail($user);
        $body = (string) ($mail->viewData['body'] ?? '');

        expect($mail->subject)->toBe('Reset je wachtwoord');
        expect($body)->toContain('Wachtwoord resetten');
        expect($body)->toContain('Deze resetlink verloopt over 60 minuten.');

        return true;
    });
});

it('uses the password reset email template from the database', function () {
    config()->set('app.frontend_url', 'https://innerr.app');
    Notification::fake();

    EmailTemplate::query()
        ->where('key', EmailTemplateRegistry::PASSWORD_RESET)
        ->update([
            'subject_en' => 'Custom subject',
            'body_en' => '# Hi {recipient_name}!

Click [here]({reset_url}) to reset (expires in {minutes} minutes).',
        ]);

    $user = User::factory()->create(['email' => 'jane@example.com', 'locale' => 'en', 'name' => 'Jane']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        App::setLocale($user->preferredLocale());
        $mail = $notification->toMail($user);
        $body = (string) ($mail->viewData['body'] ?? '');

        expect($mail->subject)->toBe('Custom subject');
        expect($body)->toContain('# Hi Jane!');
        expect($body)->toContain('https://innerr.app/password-reset?token=');
        expect($body)->toContain('expires in 60 minutes');

        return true;
    });
});
