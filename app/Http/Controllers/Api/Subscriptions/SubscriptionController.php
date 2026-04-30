<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('activeSubscription.plan', 'activeSubscription.price');

        $plan = $user->currentPlan();
        $plan->loadMissing('prices');

        $subscription = $user->activeSubscription;

        return new JsonResponse([
            'plan' => (new PlanResource($plan))->toArray($request),
            'is_paid' => $user->isOnPaidPlan(),
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'channel' => $subscription->channel?->value,
                'status' => $subscription->status?->value,
                'auto_renew' => $subscription->auto_renew,
                'current_period_end' => $subscription->current_period_end,
                'renews_at' => $subscription->renews_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'grace_ends_at' => $subscription->grace_ends_at,
                'canceled_at' => $subscription->canceled_at,
            ] : null,
        ]);
    }
}
