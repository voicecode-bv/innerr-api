<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\Apple\AppStoreServerApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('apple:test-notification {--timeout=60 : Seconds to poll for delivery status}')]
#[Description('Ask Apple to send a TEST App Store Server Notification V2 to the configured webhook URL and report the delivery result.')]
class AppleTestNotification extends Command
{
    public function handle(AppStoreServerApi $api): int
    {
        $environment = (string) config('apple-iap.environment');
        $this->info("Requesting test notification (environment: {$environment})...");

        try {
            $request = $api->requestTestNotification();
        } catch (Throwable $e) {
            $this->error('Failed to request test notification: '.$e->getMessage());

            return self::FAILURE;
        }

        $token = (string) ($request['testNotificationToken'] ?? '');

        if ($token === '') {
            $this->error('Apple did not return a testNotificationToken. Response:');
            $this->line(json_encode($request, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $this->line("testNotificationToken: {$token}");
        $this->info('Polling delivery status...');

        $deadline = time() + (int) $this->option('timeout');

        do {
            sleep(3);

            try {
                $status = $api->getTestNotificationStatus($token);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'status 404')) {
                    $this->line('  not delivered yet (404), retrying...');

                    continue;
                }

                $this->error('Status lookup failed: '.$e->getMessage());

                return self::FAILURE;
            }

            $sendAttempts = $status['sendAttempts'] ?? [];
            $lastAttempt = is_array($sendAttempts) ? end($sendAttempts) : null;

            if (is_array($lastAttempt) && isset($lastAttempt['sendAttemptResult'])) {
                $result = (string) $lastAttempt['sendAttemptResult'];
                $this->line("  attempt: {$result}");

                if ($result === 'SUCCESS') {
                    $this->info('Apple successfully delivered the test notification to your webhook URL.');

                    return self::SUCCESS;
                }

                if (! in_array($result, ['CIRCULAR_REDIRECT', 'NO_RESPONSE', 'OTHER'], true)) {
                    $this->error("Delivery failed with result: {$result}");
                    $this->line(json_encode($status, JSON_PRETTY_PRINT));

                    return self::FAILURE;
                }
            }
        } while (time() < $deadline);

        $this->warn('Timed out waiting for delivery confirmation. Check your webhook handler logs and Apple notification history.');

        return self::FAILURE;
    }
}
