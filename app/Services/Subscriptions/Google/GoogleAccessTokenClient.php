<?php

namespace App\Services\Subscriptions\Google;

use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class GoogleAccessTokenClient
{
    public function __construct(
        private HttpFactory $http,
        private Cache $cache,
        private string $serviceAccountPath,
        private string $tokenUrl,
        private string $scope,
        private int $ttl,
    ) {}

    public function bearer(): string
    {
        return $this->cache->remember(
            'subscriptions:google:access_token',
            $this->ttl,
            fn (): string => $this->fetch(),
        );
    }

    private function fetch(): string
    {
        if (! is_readable($this->serviceAccountPath)) {
            throw new RuntimeException('Google Play service account JSON not found at '.$this->serviceAccountPath);
        }

        $sa = json_decode((string) file_get_contents($this->serviceAccountPath), true);

        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('Google Play service account JSON is malformed.');
        }

        $now = time();
        $jwt = JWT::encode(
            payload: [
                'iss' => $sa['client_email'],
                'scope' => $this->scope,
                'aud' => $this->tokenUrl,
                'exp' => $now + 3600,
                'iat' => $now,
            ],
            key: $sa['private_key'],
            alg: 'RS256',
        );

        $response = $this->http->asForm()
            ->timeout(15)
            ->post($this->tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google token exchange failed: '.$response->body());
        }

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Google token exchange returned empty access_token.');
        }

        return $token;
    }
}
