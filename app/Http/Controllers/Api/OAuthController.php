<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SocialAccountLinker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
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

        if ($oauthUser->getEmail() === null) {
            return $this->redirectToApp(['error' => 'missing_email']);
        }

        $user = $this->linker->findOrCreate($provider, $oauthUser);

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
}
