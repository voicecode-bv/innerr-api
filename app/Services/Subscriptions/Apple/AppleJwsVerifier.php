<?php

namespace App\Services\Subscriptions\Apple;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

class AppleJwsVerifier
{
    /**
     * @param  bool  $verifyChain  When false, skips x5c chain validation. Useful in tests with self-signed fixtures.
     */
    public function __construct(
        private string $rootCaPath,
        private bool $verifyChain = true,
    ) {}

    /**
     * Decode and verify a JWS produced by Apple. Returns the decoded payload.
     *
     * @return array<string, mixed>
     */
    public function verify(string $jws): array
    {
        $segments = explode('.', $jws);

        if (count($segments) !== 3) {
            throw new RuntimeException('JWS must have 3 segments.');
        }

        $header = json_decode(self::base64UrlDecode($segments[0]), true);

        if (! is_array($header) || empty($header['x5c']) || ! is_array($header['x5c'])) {
            throw new RuntimeException('JWS header is missing x5c chain.');
        }

        $alg = (string) ($header['alg'] ?? 'ES256');
        $chainPem = array_map(fn (string $b64Der): string => self::derToPem($b64Der), $header['x5c']);

        if ($this->verifyChain) {
            $this->validateChain($chainPem);
        }

        $leafPublicKey = openssl_pkey_get_public($chainPem[0]);

        if ($leafPublicKey === false) {
            throw new RuntimeException('Could not extract public key from leaf certificate.');
        }

        $decoded = JWT::decode($jws, new Key($leafPublicKey, $alg));

        return json_decode(json_encode($decoded), true) ?? [];
    }

    /**
     * Decode without verifying signature. ONLY use for parsing client transactions
     * before re-fetching authoritative data from the App Store Server API.
     *
     * @return array<string, mixed>
     */
    public function decodeUnverified(string $jws): array
    {
        $segments = explode('.', $jws);

        if (count($segments) !== 3) {
            throw new RuntimeException('JWS must have 3 segments.');
        }

        $payload = json_decode(self::base64UrlDecode($segments[1]), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<int, string>  $chainPem
     */
    private function validateChain(array $chainPem): void
    {
        if (! is_readable($this->rootCaPath)) {
            throw new RuntimeException('Apple Root CA file is not readable.');
        }

        $rootPem = file_get_contents($this->rootCaPath);

        for ($i = 0; $i < count($chainPem) - 1; $i++) {
            $issuerPubKey = openssl_pkey_get_public($chainPem[$i + 1]);

            if ($issuerPubKey === false || openssl_x509_verify($chainPem[$i], $issuerPubKey) !== 1) {
                throw new RuntimeException('JWS x5c chain failed verification at link '.$i.'.');
            }
        }

        $rootPubKey = openssl_pkey_get_public($rootPem);
        $tail = $chainPem[count($chainPem) - 1];

        if ($rootPubKey === false || openssl_x509_verify($tail, $rootPubKey) !== 1) {
            throw new RuntimeException('JWS x5c chain does not chain up to Apple Root CA G3.');
        }
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode($padded, true) ?: '';
    }

    private static function derToPem(string $base64Der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($base64Der, 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }
}
