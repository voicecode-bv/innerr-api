<?php

namespace App\Http\Resources;

use App\Models\Price;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Number;

/** @mixin Price */
class PriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel?->value,
            'interval' => $this->interval?->value,
            'currency' => $this->currency,
            'amount_minor' => $this->amount_minor,
            'amount_formatted' => Number::currency($this->amount_minor / 100, $this->currency ?? 'EUR'),
            'channel_product_id' => $this->channel_product_id,
            'is_active' => $this->is_active,
        ];
    }
}
