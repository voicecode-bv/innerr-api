<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealAttributes;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('printdeal:sync-products')]
#[Description('Mirror the Printdeal catalog into printdeal_products and refresh purchase prices for offered products')]
class SyncPrintdealProducts extends Command
{
    public function handle(PrintdealClient $printdeal): int
    {
        $seenSkus = $this->syncCatalog($printdeal);

        // Products that vanished from the catalog can no longer be ordered;
        // flag instead of delete so existing orders/config keep their context.
        $delisted = PrintdealProduct::query()
            ->whereNull('delisted_at')
            ->whereNotIn('sku', $seenSkus)
            ->update(['delisted_at' => now()]);

        $schemas = $this->refreshAttributeSchemas($printdeal);
        $repriced = $this->refreshPurchasePrices($printdeal);

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
    private function refreshAttributeSchemas(PrintdealClient $printdeal): int
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
                $product->update([
                    'attribute_schema' => $this->fetchAttributeSchema($printdeal, $product->sku),
                ]);
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
     * Normalized to [{attribute, values: [...]}]. The v2 response is keyed by
     * attribute name; a value is either a list of allowed values or a range
     * object (free numeric input), which gets an empty values list here. The
     * `externals` key holds validation rules, not an attribute, and is
     * skipped.
     *
     * @return array<int, array{attribute: string, values: array<int, string>}>
     */
    private function fetchAttributeSchema(PrintdealClient $printdeal, string $sku): array
    {
        return collect($printdeal->attributes($sku))
            ->except('externals')
            ->map(fn ($values, string $attribute): array => [
                'attribute' => $attribute,
                'values' => is_array($values) && array_is_list($values)
                    ? array_map(strval(...), $values)
                    : [],
            ])
            ->values()
            ->all();
    }

    /**
     * Refresh the purchase price (single piece) for every offered product
     * that has its order attributes configured. The price request mirrors
     * what an actual order would submit, so the margin is applied to the
     * real cost.
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
                // Grouped products: price one piece with the first value of
                // every user option (size, color, ...).
                $variantAttributes = collect($offering->user_options ?? [])
                    ->map(fn (array $option): array => [
                        'attribute' => $option['attribute'],
                        'value' => $option['values'][0] ?? '',
                    ])
                    ->all();

                $response = $printdeal->validateAndPrice(
                    $offering->sku,
                    PrintdealAttributes::withQuantity([...$offering->order_attributes, ...$variantAttributes], 1),
                );

                $price = $response['price'] ?? null;

                if (! is_numeric($price)) {
                    continue;
                }

                $offering->update([
                    'purchase_price_minor' => (int) round((float) $price * 100),
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
