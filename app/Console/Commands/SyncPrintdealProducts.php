<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('printdeal:sync-products')]
#[Description('Mirror the Printdeal catalog into printdeal_products and refresh purchase prices for offered products')]
class SyncPrintdealProducts extends Command
{
    private const PAGE_SIZE = 400;

    public function handle(PrintdealClient $printdeal): int
    {
        $seenSkus = $this->syncCatalog($printdeal);

        // Products that vanished from the catalog can no longer be ordered;
        // flag instead of delete so existing orders/config keep their context.
        $delisted = PrintdealProduct::query()
            ->whereNull('delisted_at')
            ->whereNotIn('sku', $seenSkus)
            ->update(['delisted_at' => now()]);

        $repriced = $this->refreshPurchasePrices($printdeal);

        $this->info(sprintf(
            'Synced %d products (%d delisted), refreshed %d purchase prices.',
            count($seenSkus),
            $delisted,
            $repriced,
        ));

        return self::SUCCESS;
    }

    /**
     * Page through the full catalog and upsert every product.
     *
     * @return array<int, string> all skus seen in the catalog
     */
    private function syncCatalog(PrintdealClient $printdeal): array
    {
        $seenSkus = [];
        $offset = 0;

        do {
            $results = $printdeal->products(self::PAGE_SIZE, $offset);

            foreach ($results as $product) {
                $sku = $product['sku'] ?? null;

                if (! is_string($sku) || $sku === '') {
                    continue;
                }

                $seenSkus[] = $sku;

                PrintdealProduct::query()->updateOrCreate(['sku' => $sku], [
                    'name' => $product['name'] ?? [],
                    'synced_at' => now(),
                    'delisted_at' => null,
                ]);
            }

            $offset += self::PAGE_SIZE;
        } while (count($results) === self::PAGE_SIZE);

        return $seenSkus;
    }

    /**
     * Refresh the purchase price (gross, single piece) for every offered
     * product that has its order attributes configured. The price request
     * mirrors what an actual order would submit, so the margin is applied to
     * the real cost.
     */
    private function refreshPurchasePrices(PrintdealClient $printdeal): int
    {
        $refreshed = 0;

        $offerings = PrintdealProduct::query()
            ->offered()
            ->whereNotNull('order_attributes')
            ->get();

        foreach ($offerings as $offering) {
            try {
                $payload = [
                    'attributes' => $offering->order_attributes,
                    'deliveryType' => 'Normal',
                    'billingAddress' => ['country' => config('print.billing_address.country', 'NL')],
                ];

                if (! empty($offering->sizes)) {
                    // Grouped product: price one piece of the first size.
                    $payload['variants'] = [[
                        ['attribute' => 'Size', 'value' => $offering->sizes[0]],
                        ['attribute' => 'Quantity', 'value' => 1],
                    ]];
                } else {
                    $payload['quantities'] = [1];
                }

                $response = $printdeal->prices($offering->sku, $payload);
                $gross = $response['prices'][0]['price']['grossAmount']
                    ?? $response['variants'][0]['prices'][0]['price']['grossAmount']
                    ?? null;

                if (! is_numeric($gross)) {
                    continue;
                }

                $offering->update([
                    'purchase_price_minor' => (int) round((float) $gross * 100),
                ]);
                $refreshed++;
            } catch (\Throwable $e) {
                // One product failing to price must not abort the whole sync.
                Log::warning("Printdeal price refresh failed for {$offering->sku}", [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $refreshed;
    }
}
