<?php

namespace App\Filament\Widgets;

use App\Models\SubscriptionTransaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class RevenueByMonth extends ChartWidget
{
    protected ?string $heading = 'Omzet per maand (laatste 12 maanden)';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        $start = now()->startOfMonth()->subMonths(11);

        $rows = SubscriptionTransaction::query()
            ->where('occurred_at', '>=', $start)
            ->selectRaw("date_trunc('month', occurred_at) AS month, SUM(amount_minor) AS minor")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy(fn ($row): string => Carbon::parse($row->month)->format('Y-m'));

        $labels = [];
        $data = [];

        for ($i = 0; $i < 12; $i++) {
            $bucket = (clone $start)->addMonths($i);
            $key = $bucket->format('Y-m');
            $labels[] = $bucket->isoFormat('MMM YYYY');
            $data[] = round(((int) ($rows->get($key)?->minor ?? 0)) / 100, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Omzet (EUR)',
                    'data' => $data,
                    'backgroundColor' => '#34d399',
                    'borderColor' => '#059669',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
