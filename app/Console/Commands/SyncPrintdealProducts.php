<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
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
     * Normalized to [{attribute, values: [...]}]. The v3 beta's details
     * endpoint 404s for some catalog skus; validating an empty selection
     * returns the same schema via `remainingOptions`.
     *
     * @return array<int, array{attribute: string, values: array<int, string>}>
     */
    private function fetchAttributeSchema(PrintdealClient $printdeal, string $sku): array
    {
        try {
            $details = $printdeal->product($sku);

            return collect($details['attributes'] ?? [])
                ->map(fn (array $attribute): array => [
                    'attribute' => $attribute['name'],
                    'values' => collect($attribute['values'] ?? [])->pluck('name')->values()->all(),
                ])
                ->values()
                ->all();
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }

        $validation = $printdeal->validateSelection($sku, []);

        return collect($validation['remainingOptions'] ?? [])
            ->map(fn (array $option): array => [
                'attribute' => (string) $option['attribute'],
                'values' => array_values($option['values'] ?? []),
            ])
            ->values()
            ->all();
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

                $userOptions = $offering->user_options ?? [];

                if ($userOptions !== []) {
                    // Grouped product: price one piece with the first value
                    // of every user option (size, color, ...).
                    $variant = collect($userOptions)
                        ->map(fn (array $option): array => [
                            'attribute' => $option['attribute'],
                            'value' => $option['values'][0] ?? '',
                        ])
                        ->values()
                        ->all();
                    $variant[] = ['attribute' => 'Quantity', 'value' => 1];

                    $payload['variants'] = [$variant];
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
