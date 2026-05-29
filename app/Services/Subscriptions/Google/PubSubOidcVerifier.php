<?php

namespace App\Services\Subscriptions\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class PubSubOidcVerifier
{
    /**
     * Issuers Google uses for OIDC tokens.
     *
     * @var array<int, string>
     */
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private string $jwksUrl,
        private int $jwksCacheTtl,
        private ?string $expectedAudience,
        private ?string $expectedEmail = null,
        private bool $verifySignature = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array
    {
        if (! $this->verifySignature) {
            return $this->decodeUnverified($token);
        }

        $jwks = $this->cache->remember(
            'subscriptions:google:jwks',
            $this->jwksCacheTtl,
            fn (): array => $this->fetchJwks(),
        );

        $keys = JWK::parseKeySet($jwks);
        $decoded = JWT::decode($token, $keys);
        $payload = json_decode(json_encode($decoded), true) ?? [];

        // Issuer is always enforced: the token must come from Google itself.
        if (! in_array((string) ($payload['iss'] ?? ''), self::ISSUERS, true)) {
            throw new RuntimeException('Pub/Sub OIDC issuer mismatch.');
        }

        // Audience binds the token to our push endpoint; enforced when configured.
        if ($this->expectedAudience !== null && (string) ($payload['aud'] ?? '') !== $this->expectedAudience) {
            throw new RuntimeException('Pub/Sub OIDC audience mismatch.');
        }

        // Service-account email binds the token to the subscription's push
        // identity, so a token from any other Google service account is rejected;
        // enforced when configured.
        if ($this->expectedEmail !== null) {
            $verified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (! $verified || ! hash_equals($this->expectedEmail, (string) ($payload['email'] ?? ''))) {
                throw new RuntimeException('Pub/Sub OIDC service account mismatch.');
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeUnverified(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('OIDC token must have 3 segments.');
        }

        $payload = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        $response = $this->http->acceptJson()->timeout(15)->get($this->jwksUrl);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to fetch Google JWKS: '.$response->status());
        }

        return (array) $response->json();
    }
}
