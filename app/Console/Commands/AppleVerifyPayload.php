<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('apple:verify-payload {payload? : The signedPayload JWS to verify (or read from STDIN)}')]
#[Description('Run an Apple signedPayload through AppleJwsVerifier with chain validation to debug webhook failures.')]
class AppleVerifyPayload extends Command
{
    public function handle(AppleJwsVerifier $verifier): int
    {
        $signedPayload = (string) $this->argument('payload');

        if ($signedPayload === '') {
            $signedPayload = trim((string) fgets(STDIN));
        }

        if ($signedPayload === '') {
            $this->error('No signedPayload provided.');

            return self::FAILURE;
        }

        $this->line('Step 1: decode unverified...');

        try {
            $unverified = $verifier->decodeUnverified($signedPayload);
            $this->line('  OK — payload keys: '.implode(', ', array_keys($unverified)));
        } catch (Throwable $e) {
            $this->error('  FAIL: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Step 2: verify with chain validation...');

        try {
            $verified = $verifier->verify($signedPayload);
            $this->info('  SUCCESS — full chain validated.');
            $this->line('  notificationType: '.($verified['notificationType'] ?? 'n/a'));
            $this->line('  notificationUUID: '.($verified['notificationUUID'] ?? 'n/a'));
            $this->line('  bundleId:         '.($verified['data']['bundleId'] ?? 'n/a'));
            $this->line('  environment:      '.($verified['data']['environment'] ?? 'n/a'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('  FAIL: '.$e->getMessage());
            $this->newLine();
            $this->line('Possible causes:');
            $this->line('  - apple-root-ca-g3.pem mismatch or missing');
            $this->line('  - Apple changed intermediate cert (rare)');
            $this->line('  - openssl extension issue');

            return self::FAILURE;
        }
    }
}
