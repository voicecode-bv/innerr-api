<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealClient;
use App\Services\Printdeal\PrintdealProductSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('printdeal:sync-products')]
#[Description('Mirror the Printdeal catalog into printdeal_products and refresh purchase prices for offered products')]
class SyncPrintdealProducts extends Command
{
    public function handle(PrintdealClient $printdeal, PrintdealProductSync $sync): int
    {
        $seenSkus = $this->syncCatalog($printdeal);

        // Products that vanished from the catalog can no longer be ordered;
        // flag instead of delete so existing orders/config keep their context.
        $delisted = PrintdealProduct::query()
            ->whereNull('delisted_at')
            ->whereNotIn('sku', $seenSkus)
            ->update(['delisted_at' => now()]);

        $schemas = $this->refreshAttributeSchemas($sync);
        $repriced = $this->refreshPurchasePrices($sync);

        $this->info(sprintf(
            'Synced %d products (%d delisted), refreshed %d attribute schemas and %d purchase prices.',
            count($seenSkus),
            $delisted,
            $schemas,
            $repriced,
        ));

        return self::SUCCESS;
    }

    /**
     * Upsert every orderable product. In v2 the categories endpoint is the
     * catalog: each category carries the sku that orders and attribute
     * lookups use, with a single (English) display name.
     *
     * @return array<int, string> all skus seen in the catalog
     */
    private function syncCatalog(PrintdealClient $printdeal): array
    {
        $seenSkus = [];

        foreach ($printdeal->categories() as $product) {
            $sku = $product['sku'] ?? null;

            if (! is_string($sku) || $sku === '') {
                continue;
            }

            $seenSkus[] = $sku;

            PrintdealProduct::query()->updateOrCreate(['sku' => $sku], [
                // The name column predates v2 and stores a locale map.
                'name' => ['en-EN' => (string) ($product['name'] ?? $sku)],
                'synced_at' => now(),
                'delisted_at' => null,
            ]);
        }

        return $seenSkus;
    }

    /**
     * Store the attribute schema for every product that is mapped or enabled
     * in the admin, so the order-attributes form can suggest valid names and
     * values. Mapping happens before the attributes are known, hence the
     * wide net: map + save + sync, then the schema is there to pick from.
     */
    private function refreshAttributeSchemas(PrintdealProductSync $sync): int
    {
        $refreshed = 0;

        $configured = PrintdealProduct::query()
            ->whereNull('delisted_at')
            ->where(fn ($query) => $query
                ->where('enabled', true)
                ->orWhereNotNull('app_product'))
            ->get();

        foreach ($configured as $product) {
            try {
                $sync->refreshAttributeSchema($product);
                $refreshed++;
            } catch (\Throwable $e) {
                Log::warning("Printdeal schema refresh failed for {$product->sku}", [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $refreshed;
    }

    /**
     * Refresh the purchase price for every offered product that has its
     * order attributes configured.
     */
    private function refreshPurchasePrices(PrintdealProductSync $sync): int
    {
        $refreshed = 0;

        $offerings = PrintdealProduct::query()
            ->offered()
            ->where(fn ($query) => $query
                ->whereNotNull('order_attributes')
                ->orWhereNotNull('user_options'))
            ->get();

        foreach ($offerings as $offering) {
            try {
                if ($sync->refreshPurchasePrice($offering)) {
                    $refreshed++;
                }
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
