<?php

namespace App\Enums;

enum SubscriptionChannel: string
{
    case Apple = 'apple';
    case Google = 'google';
    case Mollie = 'mollie';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
