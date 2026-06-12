<?php

namespace App\Models;

use App\Enums\PrintOrderStatus;
use Database\Factories\PrintOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A print-product order: photos picked in the app, a product from the config
 * catalog (config/print.php), a Mollie payment, and after payment a Printdeal
 * order. `photos` stores storage paths (never URLs); signed URLs are minted at
 * submission time because they expire.
 */
#[Fillable([
    'user_id', 'product', 'options', 'photos', 'shipping_address',
    'printdeal_sku', 'printdeal_attributes',
    'amount_minor', 'currency', 'status', 'mollie_payment_id',
    'printdeal_order_id', 'printdeal_order_number', 'printdeal_item_id',
    'printdeal_status', 'pdf_path',
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
            'options' => 'array',
            'photos' => 'array',
            'shipping_address' => 'array',
            'printdeal_attributes' => 'array',
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
}
