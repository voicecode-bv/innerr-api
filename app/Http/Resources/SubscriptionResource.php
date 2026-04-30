<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel?->value,
            'status' => $this->status?->value,
            'auto_renew' => $this->auto_renew,
            'started_at' => $this->started_at,
            'current_period_start' => $this->current_period_start,
            'current_period_end' => $this->current_period_end,
            'trial_ends_at' => $this->trial_ends_at,
            'grace_ends_at' => $this->grace_ends_at,
            'canceled_at' => $this->canceled_at,
            'ended_at' => $this->ended_at,
            'renews_at' => $this->renews_at,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'price' => new PriceResource($this->whenLoaded('price')),
        ];
    }
}
