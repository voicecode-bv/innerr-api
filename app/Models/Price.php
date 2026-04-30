<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionChannel;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['plan_id', 'channel', 'interval', 'currency', 'amount_minor', 'channel_product_id', 'is_active', 'external_metadata'])]
class Price extends Model
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => SubscriptionChannel::class,
            'interval' => BillingInterval::class,
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
            'external_metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
