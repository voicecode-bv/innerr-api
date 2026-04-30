<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Widgets\ChartWidget;

class SubscriptionsByChannel extends ChartWidget
{
    protected ?string $heading = 'Active subscriptions by channel';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $rows = Subscription::query()
            ->whereIn('status', SubscriptionStatus::entitledValues())
            ->selectRaw('channel, COUNT(*) AS total')
            ->groupBy('channel')
            ->pluck('total', 'channel');

        return [
            'datasets' => [
                [
                    'label' => 'Active',
                    'data' => $rows->values()->all(),
                    'backgroundColor' => ['#60a5fa', '#34d399', '#f59e0b'],
                ],
            ],
            'labels' => $rows->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
