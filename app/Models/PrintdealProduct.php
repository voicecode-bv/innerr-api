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
    'order_attributes', 'user_options', 'attribute_schema',
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

    /** Ready to be ordered: offered, with order attributes and a price. */
    public function isOrderable(): bool
    {
        return $this->enabled
            && $this->delisted_at === null
            && $this->app_product !== null
            && ! empty($this->order_attributes)
            && $this->sellingPriceMinor() !== null;
    }

    /** Display name for the admin UI, preferring Dutch. */
    public function displayName(): string
    {
        $name = $this->name ?? [];

        return $name['nl-NL'] ?? $name['en-EN'] ?? (array_values($name)[0] ?? $this->sku);
    }
}
