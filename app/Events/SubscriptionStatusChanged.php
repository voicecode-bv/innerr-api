<?php

namespace App\Events;

use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public ?SubscriptionStatus $from,
        public SubscriptionStatus $to,
        public SubscriptionEventType $cause,
    ) {}
}
