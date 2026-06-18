<?php

namespace App\Services\Printdeal;

use App\Models\PrintdealProduct;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves what a specific configuration of an offering costs and sells for.
 * Customer-chosen options (puzzle size, packing, ...) change the Printdeal
 * price, so the purchase price is quoted live for the exact combination; the
 * synced base price only covers option-less configurations. Quotes are
 * cached briefly so browsing the options sheet doesn't hammer Printdeal.
 */
class PrintOfferingPricing
{
    private const CACHE_TTL_HOURS = 6;

    public function __construct(private PrintdealClient $printdeal) {}

    /**
     * What the user pays for this configuration (incl. VAT): a fixed selling
     * price wins regardless of options (entered incl. VAT; the merchant
     * absorbs cost differences); otherwise the margin is applied to the quoted
     * net purchase price and grossed up by VAT. Null when no price can be
     * determined, which blocks the order.
     *
     * @param  array<string, string>  $options
     */
    public function sellingPriceMinor(PrintdealProduct $offering, array $options): ?int
    {
        if ($offering->fixed_price_minor !== null) {
            return $offering->fixed_price_minor;
        }

        if ($offering->margin_percent === null) {
            return null;
        }

        $purchase = $this->purchasePriceMinor($offering, $options);

        if ($purchase === null) {
            return null;
        }

        // Round to a fraction of a cent first: float artifacts would
        // otherwise push exact outcomes (3000 * 1.10) up a whole cent. The
        // Printdeal quote is ex-VAT, so the net+margin price is grossed up to
        // the consumer price (see PrintdealProduct::vatMultiplier()).
        return (int) ceil(round(
            $purchase * (1 + $offering->margin_percent / 100) * PrintdealProduct::vatMultiplier(),
            4,
        ));
    }

    /**
     * Purchase price for one piece of the exact configuration. Option-less
     * configurations use the synced base price (and fall back to it when the
     * live call fails); configurations with options always need the live
     * quote, because a wrong price here means charging the customer wrongly.
     *
     * @param  array<string, string>  $options
     */
    public function purchasePriceMinor(PrintdealProduct $offering, array $options): ?int
    {
        if ($options === [] && $offering->purchase_price_minor !== null) {
            return $offering->purchase_price_minor;
        }

        $attributes = [
            ...$offering->order_attributes ?? [],
            ...collect($options)
                ->map(fn (string $value, string $attribute): array => [
                    'attribute' => $attribute,
                    'value' => $value,
                ])
                ->values()
                ->all(),
        ];

        return $this->quotedPriceMinor($offering->sku, $attributes)
            ?? ($options === [] ? $offering->purchase_price_minor : null);
    }

    /**
     * Cached single-piece quote for an exact attribute set. Null when the
     * combination doesn't price (invalid selection, outage); shared by the
     * live quotes and the base-price sync so they never disagree.
     *
     * @param  array<int, array{attribute: string, value: mixed}>  $attributes
     */
    public function quotedPriceMinor(string $sku, array $attributes): ?int
    {
        $cacheKey = sprintf(
            'printdeal:price:%s:%s',
            $sku,
            md5(json_encode($attributes)),
        );

        try {
            $response = Cache::remember(
                $cacheKey,
                now()->addHours(self::CACHE_TTL_HOURS),
                fn (): array => $this->printdeal->validateAndPrice(
                    $sku,
                    PrintdealAttributes::withQuantity($attributes, 1),
                ),
            );
        } catch (\Throwable) {
            return null;
        }

        $price = $response['price'] ?? null;

        return is_numeric($price) ? (int) round((float) $price * 100) : null;
    }
}
