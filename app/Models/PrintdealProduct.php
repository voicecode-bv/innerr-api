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
    'order_attributes', 'sizes', 'fixed_price_minor', 'margin_percent',
    'purchase_price_minor', 'currency',
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
            'sizes' => 'array',
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

    /** The offering that currently backs the given app product, if any. */
    public static function offeredFor(string $appProduct): ?self
    {
        return self::query()
            ->offered()
            ->where('app_product', $appProduct)
            ->orderByDesc('updated_at')
            ->first();
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
            return (int) ceil($this->purchase_price_minor * (1 + $this->margin_percent / 100));
        }

        return null;
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
