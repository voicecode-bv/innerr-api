<?php

namespace App\Models;

use App\Enums\Entitlement;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable(['slug', 'name', 'description', 'tier', 'is_default', 'is_active', 'sort_order', 'features', 'entitlements', 'metadata'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'features' => 'array',
            'entitlements' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function grants(Entitlement|string $entitlement): bool
    {
        $key = $entitlement instanceof Entitlement ? $entitlement->value : $entitlement;

        return in_array($key, $this->entitlements ?? [], true);
    }

    public function maxStorageBytes(): ?int
    {
        $gb = data_get($this->features, 'max_storage_gb');

        return is_numeric($gb) ? (int) $gb * 1024 * 1024 * 1024 : null;
    }

    public static function default(): self
    {
        return Cache::rememberForever('subscriptions:default_plan', function (): self {
            return self::query()->where('is_default', true)->firstOrFail();
        });
    }

    public static function flushDefaultCache(): void
    {
        Cache::forget('subscriptions:default_plan');
    }
}
