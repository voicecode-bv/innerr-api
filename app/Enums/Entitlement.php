<?php

namespace App\Enums;

enum Entitlement: string
{
    case Storage1Gb = 'storage_1gb';
    case Storage100Gb = 'storage_100gb';
    case Storage1Tb = 'storage_1tb';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }

    public function storageBytes(): ?int
    {
        return match ($this) {
            self::Storage1Gb => 1 * 1024 * 1024 * 1024,
            self::Storage100Gb => 100 * 1024 * 1024 * 1024,
            self::Storage1Tb => 1024 * 1024 * 1024 * 1024,
        };
    }
}
