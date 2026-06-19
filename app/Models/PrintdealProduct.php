<?php

namespace App\Models;

use App\Services\Printdeal\PrintArtworkGenerator;
use App\Services\Printdeal\PrintOfferingPricing;
use Database\Factories\PrintdealProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A product from the Printdeal catalog, mirrored locally by the
 * `printdeal:sync-products` command. The sync owns sku/name/synced_at/
 * delisted_at/purchase_price_minor; everything else (enabled, app_product,
 * order_attributes, sizes, pricing) is shop configuration managed in the
 * Filament admin.
 */
#[Fillable([
    'sku', 'name', 'synced_at', 'delisted_at', 'enabled', 'app_product',
    'order_attributes', 'user_options', 'attribute_schema', 'artwork',
    'fixed_price_minor', 'margin_percent', 'purchase_price_minor', 'currency',
])]
class PrintdealProduct extends Model
{
    /** @use HasFactory<PrintdealProductFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'synced_at' => 'datetime',
            'delisted_at' => 'datetime',
            'enabled' => 'boolean',
            'order_attributes' => 'array',
            'user_options' => 'array',
            'attribute_schema' => 'array',
            'artwork' => 'array',
            'fixed_price_minor' => 'integer',
            'margin_percent' => 'float',
            'purchase_price_minor' => 'integer',
        ];
    }

    /**
     * Products that back an app product and are switched on in the admin.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query
            ->where('enabled', true)
            ->whereNotNull('app_product')
            ->whereNull('delisted_at');
    }

    /**
     * What the user pays (incl. VAT): a fixed selling price wins (entered
     * incl. VAT); otherwise the net purchase price plus the margin, grossed up
     * by VAT. Null when neither is configured, which makes the product
     * unorderable.
     */
    public function sellingPriceMinor(): ?int
    {
        if ($this->fixed_price_minor !== null) {
            return $this->fixed_price_minor;
        }

        if ($this->purchase_price_minor !== null && $this->margin_percent !== null) {
            // Round to a fraction of a cent first: float artifacts would
            // otherwise push exact outcomes (3000 * 1.10) up a whole cent.
            return (int) ceil(round(
                $this->purchase_price_minor * (1 + $this->margin_percent / 100) * self::vatMultiplier(),
                4,
            ));
        }

        return null;
    }

    /**
     * Multiplier that grosses a net price up to the consumer price. Printdeal
     * quotes ex-VAT but invoices incl. VAT, so a margin-based price must add
     * VAT; the input VAT is reclaimable, so the margin still lands on the net
     * purchase price. Shared with {@see PrintOfferingPricing}.
     */
    public static function vatMultiplier(): float
    {
        return 1 + (float) config('print.vat_percent', 21) / 100;
    }

    /**
     * The print PDF size (mm) for a chosen option combination, or null when no
     * artwork sizing is configured (the generator then falls back to
     * config/print.php). The admin enters the trim (base) size per option; a
     * frame option (canvas) wraps around every edge and adds twice its depth,
     * and the print bleed is added on every edge on top (config
     * print.artwork_bleed_mm). So a 120×80 cm canvas with a 4.5 cm frame at
     * 3 mm bleed becomes 1200 + 2·45 + 2·3 = 1296 by 800 + 90 + 6 = 896 mm.
     *
     * @param  array<string, string>  $options
     * @return array{width: int, height: int}|null
     */
    public function artworkDimensions(array $options): ?array
    {
        $artwork = $this->artwork ?? [];
        $sizes = $artwork['sizes'] ?? [];

        if ($sizes === []) {
            return null;
        }

        $sizeAttribute = $artwork['size_attribute'] ?? null;

        // With a size attribute, match the customer's choice; without one the
        // product has a single fixed size (the first entry).
        $size = $sizeAttribute !== null
            ? collect($sizes)->firstWhere('value', $options[$sizeAttribute] ?? null)
            : ($sizes[0] ?? null);

        if ($size === null) {
            return null;
        }

        $width = (int) $size['width'];
        $height = (int) $size['height'];

        $frameAttribute = $artwork['frame_attribute'] ?? null;
        $frame = $frameAttribute !== null
            ? collect($artwork['frames'] ?? [])->firstWhere('value', $options[$frameAttribute] ?? null)
            : null;

        if ($frame !== null) {
            $width += 2 * (int) $frame['depth'];
            $height += 2 * (int) $frame['depth'];
        }

        // Print bleed on every edge, added in code so admins enter trim sizes.
        $bleed = (int) config('print.artwork_bleed_mm', 3);
        $width += 2 * $bleed;
        $height += 2 * $bleed;

        return ['width' => $width, 'height' => $height];
    }

    /**
     * The full-bleed print box (mm) of every selectable size, for the
     * resolution hint the catalog endpoint annotates each offering with.
     *
     * Each entry pairs a size-attribute value (null for a single fixed size)
     * with the page a photo is cover-cropped to fill. The box is the size's
     * trim plus print bleed, frame excluded: a frame only enlarges the box and
     * is a secondary choice the checkout still guards, so the hint reflects the
     * best case for the size. Falls back to the config/print.php full-bleed box
     * when no artwork sizing is configured (calendar, album, mug, t-shirt).
     *
     * @return list<array{value: ?string, width: int, height: int}>
     */
    public function artworkSizeBoxes(): array
    {
        if (! $this->artworkSizingConfigured()) {
            $box = PrintArtworkGenerator::fullBleedBoxMm((string) $this->app_product);

            return $box === null
                ? []
                : [['value' => null, 'width' => (int) round($box['width']), 'height' => (int) round($box['height'])]];
        }

        $sizeAttribute = $this->artwork['size_attribute'] ?? null;

        return collect($this->artwork['sizes'] ?? [])
            ->map(function (array $size) use ($sizeAttribute): ?array {
                $value = $size['value'] ?? null;
                $dimensions = $this->artworkDimensions(
                    $sizeAttribute !== null ? [$sizeAttribute => $value] : [],
                );

                return $dimensions === null
                    ? null
                    : ['value' => $value, 'width' => $dimensions['width'], 'height' => $dimensions['height']];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether this product is configured for artwork sizing: a size option is
     * named, or at least one size row exists. When true, a chosen combination
     * that does not resolve to a size (see {@see artworkDimensions()}) is a
     * misconfiguration and the order must be refused rather than silently
     * printed at the config/print.php fallback box.
     */
    public function artworkSizingConfigured(): bool
    {
        $artwork = $this->artwork ?? [];

        return ($artwork['size_attribute'] ?? null) !== null
            || ($artwork['sizes'] ?? []) !== [];
    }

    /**
     * Validate a customer's option choices against the configured user
     * options: every option must be chosen, every value must be allowed,
     * and unknown options are rejected. Shared by the order request and the
     * price-quote endpoint.
     *
     * @param  array<string, string>  $options
     * @return array<string, string> attribute => problem description
     */
    public function optionErrors(array $options): array
    {
        $errors = [];

        foreach ($this->user_options ?? [] as $userOption) {
            $chosen = $options[$userOption['attribute']] ?? null;

            if (! in_array($chosen, $userOption['values'] ?? [], true)) {
                $errors[$userOption['attribute']] = "Choose a valid {$userOption['attribute']}.";
            }
        }

        $known = collect($this->user_options ?? [])->pluck('attribute');

        foreach (array_keys($options) as $key) {
            if (! $known->contains($key)) {
                $errors[$key] = 'Unknown option for this product.';
            }
        }

        return $errors;
    }

    /**
     * Ready to be ordered: offered, attributes configured, and priced. The
     * attribute configuration may live entirely in user options (every
     * choice belongs to the customer); fixed order attributes are optional.
     */
    public function isOrderable(): bool
    {
        return $this->enabled
            && $this->delisted_at === null
            && $this->app_product !== null
            && (! empty($this->order_attributes) || ! empty($this->user_options))
            && $this->sellingPriceMinor() !== null;
    }

    /** Display name for the admin UI, preferring Dutch. */
    public function displayName(): string
    {
        $name = $this->name ?? [];

        return $name['nl-NL'] ?? $name['en-EN'] ?? (array_values($name)[0] ?? $this->sku);
    }
}
