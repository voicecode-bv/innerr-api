<?php

namespace App\Services;

use App\Enums\EmailVerificationResult;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class EmailVerificationService
{
    private const CACHE_PREFIX = 'email-verification:';

    /**
     * Generate a fresh one-time code, store its hash and notify the user.
     */
    public function send(User $user): void
    {
        $code = $this->generateCode();
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put($this->cacheKey($user), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->timestamp,
            'sent_at' => now()->timestamp,
        ], $expiresAt);

        $user->notify(new VerifyEmailNotification($code, $this->ttlMinutes()));
    }

    /**
     * Check a submitted code against the active code for the user. A correct
     * code marks the email as verified and clears the stored code; an incorrect
     * code burns one attempt and invalidates the code once the attempt limit is
     * reached, forcing the user to request a new one.
     */
    public function verify(User $user, string $code): EmailVerificationResult
    {
        if ($user->email_verified_at !== null) {
            return EmailVerificationResult::AlreadyVerified;
        }

        $key = $this->cacheKey($user);

        /** @var array{hash: string, attempts: int, expires_at: int, sent_at: int}|null $payload */
        $payload = Cache::get($key);

        if ($payload === null) {
            return EmailVerificationResult::NoActiveCode;
        }

        if (Hash::check($code, $payload['hash'])) {
            Cache::forget($key);
            $user->forceFill(['email_verified_at' => now()])->save();

            return EmailVerificationResult::Verified;
        }

        $payload['attempts']++;

        if ($payload['attempts'] >= $this->maxAttempts()) {
            Cache::forget($key);

            return EmailVerificationResult::NoActiveCode;
        }

        // Re-store with the remaining lifetime so a wrong guess can't extend the
        // window the code stays valid.
        Cache::put($key, $payload, max(1, $payload['expires_at'] - now()->timestamp));

        return EmailVerificationResult::InvalidCode;
    }

    /**
     * Seconds the user must wait before another code may be sent. Zero means a
     * resend is allowed right now.
     */
    public function secondsUntilResendAllowed(User $user): int
    {
        /** @var array{sent_at?: int}|null $payload */
        $payload = Cache::get($this->cacheKey($user));

        if ($payload === null || ! isset($payload['sent_at'])) {
            return 0;
        }

        $elapsed = now()->timestamp - (int) $payload['sent_at'];

        return max(0, $this->cooldownSeconds() - $elapsed);
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function cacheKey(User $user): string
    {
        return self::CACHE_PREFIX.$user->id;
    }

    private function ttlMinutes(): int
    {
        return (int) config('verification.code_ttl_minutes', 15);
    }

    private function maxAttempts(): int
    {
        return (int) config('verification.max_attempts', 5);
    }

    private function cooldownSeconds(): int
    {
        return (int) config('verification.resend_cooldown_seconds', 60);
    }
}
