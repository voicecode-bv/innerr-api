<?php

namespace App\Services\Subscriptions\Dto;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use Illuminate\Support\Carbon;

final readonly class WebhookOutcomeDto
{
    public function __construct(
        public SubscriptionChannel $channel,
        public SubscriptionEventType $type,
        public string $externalEventId,
        public ?string $channelSubscriptionId = null,
        public ?Carbon $occurredAt = null,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}
}
