<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionTransaction;
use Filament\Widgets\ChartWidget;

class RevenueByChannel extends ChartWidget
{
    protected ?string $heading = 'Omzet per kanaal (dit jaar)';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $rows = SubscriptionTransaction::query()
            ->where('occurred_at', '>=', now()->startOfYear())
            ->selectRaw('channel, SUM(amount_minor) AS minor')
            ->groupBy('channel')
            ->pluck('minor', 'channel');

        $colors = [
            'mollie' => '#60a5fa',
            'apple' => '#34d399',
            'google' => '#f59e0b',
        ];

        $labels = [];
        $data = [];
        $bgs = [];

        foreach ($rows as $channel => $minor) {
            $labels[] = (string) $channel;
            $data[] = round(((int) $minor) / 100, 2);
            $bgs[] = $colors[(string) $channel] ?? '#9ca3af';
        }

        return [
            'datasets' => [
                [
                    'label' => 'Omzet (EUR)',
                    'data' => $data,
                    'backgroundColor' => $bgs,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
