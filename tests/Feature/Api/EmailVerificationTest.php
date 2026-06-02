<?php

use App\Enums\EmailVerificationResult;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\EmailVerificationService;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a verification code and returns the plain code by capturing it from the
 * dispatched notification. The cache still receives the hashed code because the
 * service stores it before notifying.
 */
function sendVerificationCode(User $user): string
{
    Notification::fake();
    app(EmailVerificationService::class)->send($user);

    $captured = '';
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use (&$captured) {
        $captured = $notification->code;

        return true;
    });

    return $captured;
}

it('sends a verification code when a user registers', function () {
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'johndoe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated();

    $user = User::where('email', 'john@example.com')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('requires authentication to verify or resend', function () {
    $this->postJson('/api/auth/email/verify', ['code' => '123456'])->assertUnauthorized();
    $this->postJson('/api/auth/email/resend')->assertUnauthorized();
});

it('verifies the email with a correct code', function () {
    $user = User::factory()->unverified()->create();
    $code = sendVerificationCode($user);

    $this->actingAs($user)
        ->postJson('/api/auth/email/verify', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('user.email_verified', true)
        ->assertJsonPath('user.email_verification_required', false);

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects an incorrect code', function () {
    $user = User::factory()->unverified()->create();
    sendVerificationCode($user);

    $this->actingAs($user)
        ->postJson('/api/auth/email/verify', ['code' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects verification when no active code exists', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson('/api/auth/email/verify', ['code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('treats an already verified email as success', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->postJson('/api/auth/email/verify', ['code' => '123456'])
        ->assertOk()
        ->assertJsonPath('user.email_verified', true);
});

it('resends a fresh verification code', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson('/api/auth/email/resend')
        ->assertOk();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('does not resend once the email is verified', function () {
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->postJson('/api/auth/email/resend')
        ->assertOk();

    Notification::assertNothingSent();
});

// --- Service-level coverage (cache logic stays inside one process) ---

it('marks the email verified through the service with a correct code', function () {
    $user = User::factory()->unverified()->create();
    $code = sendVerificationCode($user);

    expect(app(EmailVerificationService::class)->verify($user, $code))
        ->toBe(EmailVerificationResult::Verified);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('invalidates the code after too many incorrect attempts', function () {
    config(['verification.max_attempts' => 3]);

    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationService::class);
    $code = sendVerificationCode($user);

    expect($service->verify($user, 'invalid'))->toBe(EmailVerificationResult::InvalidCode);
    expect($service->verify($user, 'invalid'))->toBe(EmailVerificationResult::InvalidCode);
    // Third wrong attempt hits the limit and burns the code.
    expect($service->verify($user, 'invalid'))->toBe(EmailVerificationResult::NoActiveCode);
    // Even the correct code no longer works.
    expect($service->verify($user, $code))->toBe(EmailVerificationResult::NoActiveCode);
});

it('enforces a cooldown between resends', function () {
    config(['verification.resend_cooldown_seconds' => 60]);

    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationService::class);

    Notification::fake();
    $service->send($user);

    expect($service->secondsUntilResendAllowed($user))->toBeGreaterThan(0);
});

it('does not require verification once the email is verified', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->requiresEmailVerification())->toBeFalse();
});

it('requires verification for new unverified accounts', function () {
    $user = User::factory()->unverified()->create();

    expect($user->requiresEmailVerification())->toBeTrue();
});

it('backfills email_verified_at for pre-existing unverified accounts', function () {
    $user = User::factory()->unverified()->create([
        'created_at' => '2020-01-01 00:00:00',
    ]);

    $migration = require database_path('migrations/2026_06_02_111316_backfill_email_verified_at_for_existing_users.php');
    $migration->up();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});
