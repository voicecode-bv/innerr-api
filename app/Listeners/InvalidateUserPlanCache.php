<?php

namespace App\Listeners;

use App\Events\SubscriptionStatusChanged;
use App\Models\User;

class InvalidateUserPlanCache
{
    public function handle(SubscriptionStatusChanged $event): void
    {
        User::flushPlanCache($event->subscription->user_id);
    }
}
