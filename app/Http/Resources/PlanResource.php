<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'tier' => $this->tier,
            'is_default' => $this->is_default,
            'sort_order' => $this->sort_order,
            'features' => $this->features ?? (object) [],
            'entitlements' => $this->entitlements ?? [],
            'prices' => PriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
