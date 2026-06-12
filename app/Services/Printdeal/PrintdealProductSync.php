<?php

namespace App\Services\Printdeal;

use App\Models\PrintdealProduct;

/**
 * Per-product refresh of API-derived data (attribute schema, purchase
 * price). Used by the nightly printdeal:sync-products run and one-off from
 * the admin, so a freshly mapped product is usable without waiting for the
 * next sync.
 */
class PrintdealProductSync
{
    public function __construct(private PrintdealClient $printdeal) {}

    /**
     * Fetch and store the attribute schema, normalized to
     * [{attribute, values: [...]}]. The v2 response is keyed by attribute
     * name; a value is either a list of allowed values or a range object
     * (free numeric input), which gets an empty values list here. The
     * `externals` key holds validation rules, not an attribute, and is
     * skipped.
     */
    public function refreshAttributeSchema(PrintdealProduct $product): void
    {
        $schema = collect($this->printdeal->attributes($product->sku))
            ->except('externals')
            ->map(fn ($values, string $attribute): array => [
                'attribute' => $attribute,
                'values' => is_array($values) && array_is_list($values)
                    ? array_map(strval(...), $values)
                    : [],
            ])
            ->values()
            ->all();

        $product->update(['attribute_schema' => $schema]);
    }

    /**
     * Fetch and store the purchase price (single piece) when the order
     * attributes are configured. The price request mirrors what an actual
     * order would submit, so the margin is applied to the real cost.
     *
     * @return bool whether a price was stored
     */
    public function refreshPurchasePrice(PrintdealProduct $product): bool
    {
        if (empty($product->order_attributes)) {
            return false;
        }

        // Grouped products: price one piece with the first value of every
        // user option (size, color, ...).
        $variantAttributes = collect($product->user_options ?? [])
            ->map(fn (array $option): array => [
                'attribute' => $option['attribute'],
                'value' => $option['values'][0] ?? '',
            ])
            ->all();

        $response = $this->printdeal->validateAndPrice(
            $product->sku,
            PrintdealAttributes::withQuantity([...$product->order_attributes, ...$variantAttributes], 1),
        );

        $price = $response['price'] ?? null;

        if (! is_numeric($price)) {
            return false;
        }

        $product->update([
            'purchase_price_minor' => (int) round((float) $price * 100),
        ]);

        return true;
    }
}
