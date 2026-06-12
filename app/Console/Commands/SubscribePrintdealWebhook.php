<?php

namespace App\Console\Commands;

use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\URL;

#[Signature('printdeal:subscribe-webhook')]
#[Description('Register our orderline-status webhook URL (with the shared token) at Printdeal')]
class SubscribePrintdealWebhook extends Command
{
    /**
     * One-time setup (rerun after rotating PRINTDEAL_WEBHOOK_TOKEN or moving
     * hosts). The v3 webhook API does not sign payloads, so the URL embeds
     * the shared token that PrintdealWebhookController verifies.
     */
    public function handle(PrintdealClient $printdeal): int
    {
        $token = (string) config('services.printdeal.webhook_token');

        if ($token === '') {
            $this->error('Set PRINTDEAL_WEBHOOK_TOKEN first, e.g.: php -r \'echo bin2hex(random_bytes(32));\'');

            return self::FAILURE;
        }

        $url = URL::route('api.webhooks.print.printdeal', ['token' => $token]);

        if (! str_starts_with($url, 'https://')) {
            $this->error("Webhook URL is not https ({$url}); check APP_URL. Printdeal cannot reach a local URL.");

            return self::FAILURE;
        }

        try {
            $response = $printdeal->createWebhookSubscription(
                $url,
                ['orderline.status.updated'],
                'innerr print order status updates',
            );
        } catch (RequestException $e) {
            // 409 = every requested event already has a subscription on this
            // URL, which is exactly the desired end state.
            if ($e->response->status() === 409) {
                $this->info('Already subscribed; nothing to do.');

                return self::SUCCESS;
            }

            $this->error("Printdeal rejected the subscription: {$e->response->body()}");

            return self::FAILURE;
        }

        $this->info('Subscribed to orderline.status.updated.');
        $this->line(json_encode($response, JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
