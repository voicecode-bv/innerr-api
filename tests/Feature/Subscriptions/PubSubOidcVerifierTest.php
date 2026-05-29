<?php

use App\Services\Subscriptions\Google\PubSubOidcVerifier;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const JWKS_URL = 'https://oidc.test/jwks';
const SERVICE_ACCOUNT = 'rtdn@innerr.iam.gserviceaccount.com';
const AUDIENCE = 'https://innerr.test/webhooks/subscriptions/google';

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $this->privateKey);
    $details = openssl_pkey_get_details($key);
    $this->kid = 'test-kid';

    $b64 = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    Http::fake([JWKS_URL => Http::response(['keys' => [[
        'kty' => 'RSA',
        'alg' => 'RS256',
        'use' => 'sig',
        'kid' => $this->kid,
        'n' => $b64($details['rsa']['n']),
        'e' => $b64($details['rsa']['e']),
    ]]])]);
});

function oidcVerifier(?string $audience = null, ?string $email = null): PubSubOidcVerifier
{
    return new PubSubOidcVerifier(
        http: app(HttpFactory::class),
        cache: Cache::store(),
        jwksUrl: JWKS_URL,
        jwksCacheTtl: 60,
        expectedAudience: $audience,
        expectedEmail: $email,
        verifySignature: true,
    );
}

/**
 * @param  array<string, mixed>  $claims
 */
function oidcToken(array $claims, string $privateKey, string $kid): string
{
    return JWT::encode(array_merge([
        'iss' => 'https://accounts.google.com',
        'iat' => time(),
        'exp' => time() + 3600,
    ], $claims), $privateKey, 'RS256', $kid);
}

it('accepts a valid Google-signed token matching audience and service account', function () {
    $token = oidcToken([
        'aud' => AUDIENCE,
        'email' => SERVICE_ACCOUNT,
        'email_verified' => true,
    ], $this->privateKey, $this->kid);

    $payload = oidcVerifier(AUDIENCE, SERVICE_ACCOUNT)->verify($token);

    expect($payload)->toMatchArray(['aud' => AUDIENCE, 'email' => SERVICE_ACCOUNT]);
});

it('rejects a token whose issuer is not Google', function () {
    $token = oidcToken(['iss' => 'https://accounts.evil.com', 'aud' => AUDIENCE], $this->privateKey, $this->kid);

    expect(fn () => oidcVerifier(AUDIENCE)->verify($token))
        ->toThrow(RuntimeException::class, 'issuer mismatch');
});

it('rejects a token whose audience does not match', function () {
    $token = oidcToken(['aud' => 'https://attacker.test/hook'], $this->privateKey, $this->kid);

    expect(fn () => oidcVerifier(AUDIENCE)->verify($token))
        ->toThrow(RuntimeException::class, 'audience mismatch');
});

it('rejects a token from a different service account', function () {
    $token = oidcToken([
        'aud' => AUDIENCE,
        'email' => 'attacker@evil.iam.gserviceaccount.com',
        'email_verified' => true,
    ], $this->privateKey, $this->kid);

    expect(fn () => oidcVerifier(AUDIENCE, SERVICE_ACCOUNT)->verify($token))
        ->toThrow(RuntimeException::class, 'service account mismatch');
});

it('rejects a token whose email is not verified', function () {
    $token = oidcToken([
        'aud' => AUDIENCE,
        'email' => SERVICE_ACCOUNT,
        'email_verified' => false,
    ], $this->privateKey, $this->kid);

    expect(fn () => oidcVerifier(AUDIENCE, SERVICE_ACCOUNT)->verify($token))
        ->toThrow(RuntimeException::class, 'service account mismatch');
});
