<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AppleAudienceMismatchException;
use App\Exceptions\UnverifiedAccountLinkException;
use App\Http\Controllers\Controller;
use App\Services\SocialAccountLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteTwoUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class OAuthController extends Controller
{
    public function __construct(protected SocialAccountLinker $linker) {}

    public function redirect(string $provider): mixed
    {
        $this->assertSupportedProvider($provider);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->assertSupportedProvider($provider);

        try {
            $oauthUser = Socialite::driver($provider)->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('OAuth callback failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->redirectToApp(['error' => 'oauth_failed']);
        }

        if ($provider === 'apple') {
            try {
                $this->assertAppleAudience($oauthUser);
            } catch (AppleAudienceMismatchException $e) {
                Log::warning('OAuth apple audience mismatch', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);

                return $this->redirectToApp(['error' => 'oauth_failed']);
            }
        }

        if ($oauthUser->getEmail() === null) {
            return $this->redirectToApp(['error' => 'missing_email']);
        }

        try {
            $user = $this->linker->findOrCreate($provider, $oauthUser);
        } catch (UnverifiedAccountLinkException) {
            return $this->redirectToApp(['error' => 'unverified_account_exists']);
        }

        // Verwijder alle bestaande tokens zodat er per gebruiker maar één
        // actieve sessie tegelijk bestaat; inloggen verloopt elk eerder token.
        $user->tokens()->delete();

        $token = $user->createToken('innerr-mobile')->plainTextToken;

        return $this->redirectToApp(['token' => $token]);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function redirectToApp(array $query): RedirectResponse
    {
        $callback = (string) config('oauth.mobile_callback');

        return redirect()->away($callback.'?'.http_build_query($query));
    }

    private function assertSupportedProvider(string $provider): void
    {
        if (! in_array($provider, ['google', 'apple'], true)) {
            throw new NotFoundHttpException("Unsupported OAuth provider: {$provider}");
        }
    }

    /**
     * Enforce the Apple ID token audience. The Socialite Apple provider verifies
     * signature/issuer/expiry but NOT `aud`, so a token issued to a different
     * Apple relying party for the same user would otherwise be accepted. We
     * require the token's audience to include our configured client id.
     */
    private function assertAppleAudience(SocialiteUser $oauthUser): void
    {
        $expected = (string) config('services.apple.client_id');

        if ($expected === '') {
            // Apple is not configured for this environment; the flow itself
            // cannot succeed, so there is nothing to assert against.
            return;
        }

        $idToken = $this->appleIdToken($oauthUser);
        $audience = $idToken !== null ? $this->jwtAudience($idToken) : [];

        if (! in_array($expected, $audience, true)) {
            throw new AppleAudienceMismatchException('Apple ID token audience does not match the configured client id.');
        }
    }

    /**
     * The Apple provider stores the already signature-verified id_token JWT as
     * the user's token (Provider::user() → setToken($id_token)); we re-read it
     * only to assert the audience claim.
     */
    private function appleIdToken(SocialiteUser $oauthUser): ?string
    {
        if (! $oauthUser instanceof SocialiteTwoUser) {
            return null;
        }

        return is_string($oauthUser->token) && $oauthUser->token !== ''
            ? $oauthUser->token
            : null;
    }

    /**
     * Read the `aud` claim from a JWT without re-verifying the signature (the
     * provider already verified it). Returns the audiences as a list of strings.
     *
     * @return array<int, string>
     */
    private function jwtAudience(string $jwt): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            return [];
        }

        $payload = json_decode(
            (string) base64_decode(strtr($segments[1], '-_', '+/'), true),
            true,
        );

        $audience = is_array($payload) ? ($payload['aud'] ?? null) : null;

        if (is_array($audience)) {
            return array_map('strval', $audience);
        }

        return $audience !== null ? [(string) $audience] : [];
    }
}
