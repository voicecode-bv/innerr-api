<?php

namespace App\Services;

use App\Exceptions\UnverifiedAccountLinkException;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAccountLinker
{
    public function findOrCreate(string $provider, SocialiteUser $oauthUser): User
    {
        $providerColumn = $this->providerColumn($provider);
        $providerId = (string) $oauthUser->getId();
        $email = $this->normalizeEmail($oauthUser->getEmail());

        $existingByProvider = User::query()->where($providerColumn, $providerId)->first();

        if ($existingByProvider !== null) {
            if ($oauthUser->getName() !== null && $existingByProvider->name !== $oauthUser->getName()) {
                $existingByProvider->forceFill(['name' => $oauthUser->getName()])->save();
            }

            return $existingByProvider;
        }

        if ($email !== null) {
            $existingByEmail = User::query()->where('email', $email)->first();

            if ($existingByEmail !== null) {
                // Een squatter kan een account met een willekeurig e-mailadres
                // aanmaken zonder het te verifiëren; dat onverifieerde account
                // automatisch koppelen aan OAuth-login = account-takeover. Eis
                // dat het bestaande account zijn e-mail heeft bewezen vóór we
                // het OAuth-provider-ID erop pinnen.
                if ($existingByEmail->email_verified_at === null) {
                    throw new UnverifiedAccountLinkException;
                }

                $existingByEmail->forceFill([$providerColumn => $providerId])->save();

                return $existingByEmail;
            }
        }

        $user = User::create([
            'name' => $oauthUser->getName() ?? $this->deriveNameFromEmail($email),
            'username' => $this->generateUsername($email),
            'email' => $email,
            'password' => null,
            'locale' => app()->getLocale(),
            $providerColumn => $providerId,
        ]);

        // The OAuth provider has already proven ownership of this email, so the
        // account is verified from creation. `email_verified_at` is intentionally
        // not mass-assignable (it must never be settable from request input), so
        // we set it explicitly via forceFill.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function providerColumn(string $provider): string
    {
        return match ($provider) {
            'google' => 'google_id',
            'apple' => 'apple_id',
            default => throw new \InvalidArgumentException("Unsupported provider: {$provider}"),
        };
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function deriveNameFromEmail(?string $email): string
    {
        if ($email === null) {
            return 'User';
        }

        $localPart = Str::before($email, '@');

        return ucfirst($localPart) !== '' ? ucfirst($localPart) : 'User';
    }

    private function generateUsername(?string $email): string
    {
        $base = $email !== null
            ? preg_replace('/[^a-z0-9-]/', '', mb_strtolower(Str::before($email, '@'))) ?? ''
            : '';

        if ($base === '') {
            $base = 'user';
        }

        $base = mb_substr($base, 0, 20);

        $candidate = $base;

        while (User::query()->where('username', $candidate)->exists()) {
            $candidate = $base.'-'.Str::lower(Str::random(5));
        }

        return $candidate;
    }
}
