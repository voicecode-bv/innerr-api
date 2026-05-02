<?php

namespace App\Services\Subscriptions\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class PubSubOidcVerifier
{
    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private string $jwksUrl,
        private int $jwksCacheTtl,
        private ?string $expectedAudience,
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

        if ($this->expectedAudience !== null && (string) ($payload['aud'] ?? '') !== $this->expectedAudience) {
            throw new RuntimeException('Pub/Sub OIDC audience mismatch.');
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
