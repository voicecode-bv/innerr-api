<?php

namespace App\Models;

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
     * What the user pays: a fixed selling price wins; otherwise the synced
     * purchase price (VAT inclusive) plus the margin percentage. Null when
     * neither is configured, which makes the product unorderable.
     */
    public function sellingPriceMinor(): ?int
    {
        if ($this->fixed_price_minor !== null) {
            return $this->fixed_price_minor;
        }

        if ($this->purchase_price_minor !== null && $this->margin_percent !== null) {
            // Round to a fraction of a cent first: float artifacts would
            // otherwise push exact outcomes (3000 * 1.10) up a whole cent.
            return (int) ceil(round($this->purchase_price_minor * (1 + $this->margin_percent / 100), 4));
        }

        return null;
    }

    /**
     * The print PDF size (mm) for a chosen option combination, or null when no
     * artwork sizing is configured (the generator then falls back to
     * config/print.php). The size option's value maps directly to the final
     * width/height the admin entered; a frame option (canvas) wraps around
     * every edge and so adds twice its depth to each dimension.
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

        return ['width' => $width, 'height' => $height];
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
