<?php

namespace App\Services\Subscriptions\Apple;

use Firebase\JWT\JWT;
use RuntimeException;

class AppStoreServerJwt
{
    public function __construct(
        private string $issuerId,
        private string $keyId,
        private string $bundleId,
        private string $privateKeyPath,
        private int $ttlSeconds = 1800,
    ) {}

    public function bearerToken(): string
    {
        if ($this->privateKeyPath === '' || ! is_readable($this->privateKeyPath)) {
            throw new RuntimeException('Apple IAP private key not configured or readable.');
        }

        $now = time();

        return JWT::encode(
            payload: [
                'iss' => $this->issuerId,
                'iat' => $now,
                'exp' => $now + $this->ttlSeconds,
                'aud' => 'appstoreconnect-v1',
                'bid' => $this->bundleId,
            ],
            key: file_get_contents($this->privateKeyPath),
            alg: 'ES256',
            keyId: $this->keyId,
        );
    }
}
