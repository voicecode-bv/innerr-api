<?php

namespace App\Http\Controllers\Api\Subscriptions;

use App\Enums\SubscriptionChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;

class PlansController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $channel = $request->query('channel');
        $channelFilter = $channel && in_array($channel, SubscriptionChannel::values(), true) ? $channel : null;

        $cacheKey = 'subscriptions:plans_catalog:'.($channelFilter ?? 'all');

        $plans = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($channelFilter) {
            return Plan::query()
                ->where('is_active', true)
                ->with(['prices' => function ($query) use ($channelFilter) {
                    $query->where('is_active', true);

                    if ($channelFilter) {
                        $query->where('channel', $channelFilter);
                    }
                }])
                ->orderBy('sort_order')
                ->orderBy('tier')
                ->get();
        });

        return PlanResource::collection($plans);
    }
}
