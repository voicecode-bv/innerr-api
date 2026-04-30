<?php

namespace App\Services\Subscriptions\Dto;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionStatus;
use Illuminate\Support\Carbon;

final readonly class SubscriptionStatusDto
{
    public function __construct(
        public SubscriptionChannel $channel,
        public string $channelSubscriptionId,
        public SubscriptionStatus $status,
        public ?string $channelProductId = null,
        public ?string $channelCustomerId = null,
        public ?Carbon $currentPeriodStart = null,
        public ?Carbon $currentPeriodEnd = null,
        public ?Carbon $trialEndsAt = null,
        public ?Carbon $graceEndsAt = null,
        public ?Carbon $canceledAt = null,
        public ?Carbon $renewsAt = null,
        public bool $autoRenew = true,
        public string $environment = 'production',
        public ?string $latestReceipt = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}
}
