<?php

namespace App\Enums;

enum SubscriptionEventType: string
{
    case Started = 'started';
    case Renewed = 'renewed';
    case EnteredGrace = 'entered_grace';
    case Recovered = 'recovered';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PriceChange = 'price_change';
    case Upgraded = 'upgraded';
    case Downgraded = 'downgraded';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
