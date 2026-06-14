<?php

namespace App\Models;

use Database\Factories\PrintOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product within a print order: its photos, the user-chosen options
 * (size etc.), and the offering snapshot (sku, attributes, price) taken at
 * order time so later admin edits never change what the user bought.
 */
#[Fillable([
    'print_order_id', 'app_product', 'name', 'printdeal_sku',
    'printdeal_attributes', 'options', 'artwork_width_mm', 'artwork_height_mm',
    'photos', 'amount_minor', 'pdf_path', 'printdeal_item_id', 'printdeal_status',
])]
class PrintOrderItem extends Model
{
    /** @use HasFactory<PrintOrderItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'printdeal_attributes' => 'array',
            'options' => 'array',
            'photos' => 'array',
            'amount_minor' => 'integer',
            'artwork_width_mm' => 'integer',
            'artwork_height_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PrintOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PrintOrder::class, 'print_order_id');
    }
}
