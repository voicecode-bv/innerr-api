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
use Illuminate\Support\Facades\DB;

/**
 * A print order: one or more products (see PrintOrderItem) shipped to one
 * address, paid in a single Mollie payment, and after payment submitted to
 * Printdeal as one order with multiple line items.
 *
 * `id` stays a UUID (the technical identifier used in routes, Mollie
 * metadata, and webhook lookups); `number` is the sequential, human-friendly
 * order number shown to users and quoted in support.
 */
#[Fillable([
    'user_id', 'number', 'shipping_address', 'amount_minor', 'currency',
    'status', 'mollie_payment_id', 'printdeal_order_id',
    'printdeal_order_number', 'printdeal_status',
])]
class PrintOrder extends Model
{
    /** @use HasFactory<PrintOrderFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        // Pull the next order number from the Postgres sequence so concurrent
        // inserts never collide; fetched up front so it's available on the
        // model right after create (for the Mollie/Printdeal references).
        static::creating(function (PrintOrder $order): void {
            if ($order->number === null) {
                $order->number = (int) DB::selectOne(
                    "select nextval('print_orders_number_seq') as n"
                )->n;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
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
