<?php

namespace App\Jobs\Subscriptions;

use App\Models\SubscriptionEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessSubscriptionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public string $queue = 'subscriptions';

    public function __construct(public int $eventId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        $event = SubscriptionEvent::query()->find($this->eventId);
        $key = $event?->subscription_id ?? "event:{$this->eventId}";

        return [(new WithoutOverlapping((string) $key))->expireAfter(120)];
    }

    public function handle(): void
    {
        // Phase 1+: load event, lock-for-update, dispatch to channel handler
        // via ChannelRegistry, run state machine, persist authoritative status,
        // mark processed_at. Phase 0 placeholder: simply mark as processed.
        SubscriptionEvent::query()
            ->whereKey($this->eventId)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }
}
