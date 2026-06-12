<?php

namespace App\Models;

use App\Enums\PrintOrderStatus;
use Database\Factories\PrintOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A print order: one or more products (see PrintOrderItem) shipped to one
 * address, paid in a single Mollie payment, and after payment submitted to
 * Printdeal as one order with multiple line items.
 */
#[Fillable([
    'user_id', 'shipping_address', 'amount_minor', 'currency', 'status',
    'mollie_payment_id', 'printdeal_order_id', 'printdeal_order_number',
    'printdeal_status',
])]
class PrintOrder extends Model
{
    /** @use HasFactory<PrintOrderFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'amount_minor' => 'integer',
            'status' => PrintOrderStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PrintOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrintOrderItem::class);
    }
}
