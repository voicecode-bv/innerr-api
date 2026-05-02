<?php

use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use Firebase\JWT\JWT;

it('decodes a JWS without verifying signature', function () {
    [$privatePem, $publicPem] = makeEcKeyPair();
    $jws = JWT::encode(['hello' => 'world'], $privatePem, 'ES256');

    $verifier = new AppleJwsVerifier(rootCaPath: '/dev/null', verifyChain: false);

    expect($verifier->decodeUnverified($jws))->toBe(['hello' => 'world']);
});

it('verifies a JWS using the embedded x5c leaf cert (chain validation off)', function () {
    [$privatePem, $publicPem, $certPem] = makeEcKeyPairWithSelfSignedCert();
    $derBase64 = pemCertToDerBase64($certPem);

    $jws = encodeJwsWithX5C(['notificationUUID' => 'uuid-1', 'notificationType' => 'TEST'], $privatePem, $derBase64);

    $verifier = new AppleJwsVerifier(rootCaPath: '/dev/null', verifyChain: false);

    $payload = $verifier->verify($jws);

    expect($payload)
        ->toHaveKey('notificationUUID', 'uuid-1')
        ->toHaveKey('notificationType', 'TEST');
});

it('throws when JWS header has no x5c chain', function () {
    [$privatePem] = makeEcKeyPair();
    $jws = JWT::encode(['x' => 1], $privatePem, 'ES256');

    $verifier = new AppleJwsVerifier(rootCaPath: '/dev/null', verifyChain: false);

    expect(fn () => $verifier->verify($jws))->toThrow(RuntimeException::class);
});

/**
 * @return array{0: string, 1: string}
 */
function makeEcKeyPair(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    return [$privatePem, $details['key']];
}

/**
 * @return array{0: string, 1: string, 2: string}
 */
function makeEcKeyPairWithSelfSignedCert(): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privatePem);
    $publicPem = openssl_pkey_get_details($key)['key'];

    $csr = openssl_csr_new(['CN' => 'Test'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, days: 30, options: ['digest_alg' => 'sha256']);
    openssl_x509_export($cert, $certPem);

    return [$privatePem, $publicPem, $certPem];
}

function pemCertToDerBase64(string $pem): string
{
    $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem);

    return $body;
}

/**
 * @param  array<string, mixed>  $payload
 */
function encodeJwsWithX5C(array $payload, string $privateKeyPem, string $x5cDerBase64): string
{
    $header = ['alg' => 'ES256', 'x5c' => [$x5cDerBase64]];

    return JWT::encode($payload, $privateKeyPem, 'ES256', null, $header);
}
