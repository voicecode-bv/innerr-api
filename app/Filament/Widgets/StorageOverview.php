<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class StorageOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $stats = User::query()
            ->selectRaw('COALESCE(SUM(storage_used_bytes), 0) AS total')
            ->selectRaw('COALESCE(AVG(NULLIF(storage_used_bytes, 0)), 0) AS avg_active')
            ->selectRaw('COALESCE(MAX(storage_used_bytes), 0) AS max_user')
            ->selectRaw('COUNT(*) FILTER (WHERE storage_used_bytes > 0) AS active_users')
            ->first();

        return [
            Stat::make('Total storage', Number::fileSize((int) $stats->total, precision: 2))
                ->description("{$stats->active_users} users with files")
                ->color('primary'),
            Stat::make('Average per active user', Number::fileSize((int) $stats->avg_active, precision: 2))
                ->color('success'),
            Stat::make('Largest single user', Number::fileSize((int) $stats->max_user, precision: 2))
                ->color('warning'),
        ];
    }
}
