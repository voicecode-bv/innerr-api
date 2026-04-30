<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case InGrace = 'in_grace';
    case OnHold = 'on_hold';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    /**
     * Statuses that grant entitlement to the plan's features right now.
     *
     * @return array<int, self>
     */
    public static function entitled(): array
    {
        return [self::Active, self::InGrace];
    }

    /**
     * @return array<int, string>
     */
    public static function entitledValues(): array
    {
        return array_map(fn (self $case): string => $case->value, self::entitled());
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Expired, self::Canceled, self::Refunded], true);
    }

    public function grantsAccess(): bool
    {
        return in_array($this, self::entitled(), true);
    }
}
