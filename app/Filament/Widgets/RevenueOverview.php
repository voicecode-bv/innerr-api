<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionTransaction;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class RevenueOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $monthStart = now()->startOfMonth();
        $yearStart = now()->startOfYear();

        $rows = SubscriptionTransaction::query()
            ->selectRaw('COALESCE(SUM(amount_minor) FILTER (WHERE occurred_at >= ?), 0) AS today_minor', [$today])
            ->selectRaw('COALESCE(SUM(amount_minor) FILTER (WHERE occurred_at >= ?), 0) AS month_minor', [$monthStart])
            ->selectRaw('COALESCE(SUM(amount_minor) FILTER (WHERE occurred_at >= ?), 0) AS year_minor', [$yearStart])
            ->first();

        return [
            Stat::make('Omzet vandaag', $this->format($rows->today_minor))
                ->color('primary'),
            Stat::make('Omzet deze maand', $this->format($rows->month_minor))
                ->color('success'),
            Stat::make('Omzet dit jaar', $this->format($rows->year_minor))
                ->color('warning'),
        ];
    }

    private function format(int|string $minor): string
    {
        return Number::currency(((int) $minor) / 100, 'EUR');
    }
}
