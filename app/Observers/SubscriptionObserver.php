<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionObserver
{
    public function saved(Subscription $subscription): void
    {
        User::flushPlanCache($subscription->user_id);
    }

    public function deleted(Subscription $subscription): void
    {
        User::flushPlanCache($subscription->user_id);
    }
}
