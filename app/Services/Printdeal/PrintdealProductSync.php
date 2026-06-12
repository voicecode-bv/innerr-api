<?php

namespace App\Services\Printdeal;

use App\Models\PrintdealProduct;
use Illuminate\Support\Facades\Log;

/**
 * Per-product refresh of API-derived data (attribute schema, purchase
 * price). Used by the nightly printdeal:sync-products run and one-off from
 * the admin, so a freshly mapped product is usable without waiting for the
 * next sync.
 */
class PrintdealProductSync
{
    /**
     * Ceiling on how many option combinations get priced per product. Above
     * it, the first-value combination is priced instead, so a wildly
     * configured product degrades to a possibly-off base price rather than
     * hammering Printdeal.
     */
    private const MAX_PRICED_COMBINATIONS = 100;

    public function __construct(
        private PrintdealClient $printdeal,
        private PrintOfferingPricing $pricing,
    ) {}

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
     * Refresh the stored purchase price: the LOWEST single-piece price
     * across every user-option combination, so the app's "from" price never
     * overstates. Combinations that don't price (invalid selections) are
     * skipped; quotes share the live-quote cache.
     *
     * @return bool whether a price was stored
     */
    public function refreshPurchasePrice(PrintdealProduct $product): bool
    {
        if (empty($product->order_attributes) && empty($product->user_options)) {
            return false;
        }

        $combinations = $this->optionCombinations($product->user_options ?? []);

        if (count($combinations) > self::MAX_PRICED_COMBINATIONS) {
            Log::warning("Printdeal price refresh for {$product->sku}: too many option combinations, pricing the first one only.", [
                'combinations' => count($combinations),
            ]);
            $combinations = [array_shift($combinations)];
        }

        $lowest = null;

        foreach ($combinations as $options) {
            $attributes = [
                ...$product->order_attributes ?? [],
                ...collect($options)
                    ->map(fn (string $value, string $attribute): array => [
                        'attribute' => $attribute,
                        'value' => $value,
                    ])
                    ->values()
                    ->all(),
            ];

            $price = $this->pricing->quotedPriceMinor($product->sku, $attributes);

            if ($price !== null && ($lowest === null || $price < $lowest)) {
                $lowest = $price;
            }
        }

        if ($lowest === null) {
            return false;
        }

        $product->update(['purchase_price_minor' => $lowest]);

        return true;
    }

    /**
     * Cartesian product of the user options:
     * [{Size: S, Color: Red}, {Size: S, Color: Blue}, ...]. A product
     * without options yields one empty combination (the fixed attributes).
     *
     * @param  array<int, array{attribute: string, values: array<int, string>}>  $userOptions
     * @return array<int, array<string, string>>
     */
    private function optionCombinations(array $userOptions): array
    {
        $combinations = [[]];

        foreach ($userOptions as $option) {
            $values = $option['values'] ?? [];

            if ($values === []) {
                continue;
            }

            $next = [];

            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $option['attribute'] => (string) $value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }
}
