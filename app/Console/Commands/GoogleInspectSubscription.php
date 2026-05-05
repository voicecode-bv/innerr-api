<?php

namespace App\Console\Commands;

use App\Services\Subscriptions\Google\PlayDeveloperApi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('google:inspect-subscription {token : The purchaseToken to look up via Play Developer API}')]
#[Description('Fetch authoritative subscription state from Google Play Developer API for a given purchaseToken. Useful when RTDN webhooks fail to arrive — confirms the SA + API access is healthy.')]
class GoogleInspectSubscription extends Command
{
    public function handle(PlayDeveloperApi $api): int
    {
        $token = (string) $this->argument('token');

        if ($token === '') {
            $this->error('purchaseToken is required.');

            return self::FAILURE;
        }

        $this->line("Fetching subscriptionsv2 for token prefix: ".substr($token, 0, 32).'...');

        try {
            $remote = $api->getSubscriptionV2($token);
        } catch (Throwable $e) {
            $this->error('Lookup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Found:');
        $this->line('  state:           '.($remote['subscriptionState'] ?? 'n/a'));
        $this->line('  regionCode:      '.($remote['regionCode'] ?? 'n/a'));
        $this->line('  testPurchase:    '.(isset($remote['testPurchase']) ? 'yes' : 'no'));
        $this->line('  acknowledged:    '.($remote['acknowledgementState'] ?? 'n/a'));
        $this->line('  startTime:       '.($remote['startTime'] ?? 'n/a'));
        $this->line('  linkedToken:     '.($remote['linkedPurchaseToken'] ?? 'n/a'));

        $line = (array) ($remote['lineItems'][0] ?? []);
        if ($line !== []) {
            $this->line('  productId:       '.($line['productId'] ?? 'n/a'));
            $this->line('  expiryTime:      '.($line['expiryTime'] ?? 'n/a'));
            $this->line('  autoRenew:       '.(($line['autoRenewingPlan']['autoRenewEnabled'] ?? false) ? 'yes' : 'no'));
        }

        $this->newLine();
        $this->line('Raw response:');
        $this->line(json_encode($remote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');

        return self::SUCCESS;
    }
}
