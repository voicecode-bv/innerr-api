<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class StorageDistribution extends ChartWidget
{
    protected ?string $heading = 'Users by storage tier';

    protected ?string $pollingInterval = null;

    /**
     * Buckets in bytes — match how Hetzner pricing tends to step (per-MB up
     * to a GB, then GB ranges). Edges are inclusive on the lower bound.
     *
     * @var array<int, array{label: string, max: ?int}>
     */
    private const BUCKETS = [
        ['label' => '0 (no files)', 'max' => 0],
        ['label' => '< 10 MB', 'max' => 10 * 1024 * 1024],
        ['label' => '10–100 MB', 'max' => 100 * 1024 * 1024],
        ['label' => '100 MB–1 GB', 'max' => 1024 * 1024 * 1024],
        ['label' => '1–5 GB', 'max' => 5 * 1024 * 1024 * 1024],
        ['label' => '5–10 GB', 'max' => 10 * 1024 * 1024 * 1024],
        ['label' => '> 10 GB', 'max' => null],
    ];

    protected function getData(): array
    {
        $caseSql = 'CASE';
        foreach (self::BUCKETS as $index => $bucket) {
            if ($bucket['max'] === 0) {
                $caseSql .= " WHEN storage_used_bytes = 0 THEN {$index}";
            } elseif ($bucket['max'] !== null) {
                $caseSql .= " WHEN storage_used_bytes <= {$bucket['max']} THEN {$index}";
            } else {
                $caseSql .= " ELSE {$index}";
            }
        }
        $caseSql .= ' END';

        $rows = User::query()
            ->selectRaw("{$caseSql} AS bucket, COUNT(*) AS total")
            ->groupBy(DB::raw($caseSql))
            ->pluck('total', 'bucket');

        $counts = [];
        foreach (array_keys(self::BUCKETS) as $index) {
            $counts[] = (int) ($rows[$index] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Users',
                    'data' => $counts,
                    'backgroundColor' => [
                        '#9ca3af', '#60a5fa', '#34d399', '#fbbf24',
                        '#f97316', '#ef4444', '#7c3aed',
                    ],
                ],
            ],
            'labels' => array_column(self::BUCKETS, 'label'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
